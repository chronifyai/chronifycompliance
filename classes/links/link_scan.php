<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_completiondoctor\links;

use local_completiondoctor\persistent\linkcheck_record;
use local_completiondoctor\task\check_links;
use stdClass;

/**
 * Link checks of a course: queued on request (the "Check links" button) or automatically for new
 * courses by a scheduled task that compares the course's cache revision with the last check, so normal
 * Moodle requests never pay for it.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_scan {
    /** @var string Waiting for the task. */
    public const STATUS_QUEUED = 'queued';
    /** @var string Task running. */
    public const STATUS_RUNNING = 'running';
    /** @var string Done. */
    public const STATUS_FINISHED = 'finished';
    /** @var string The task failed. */
    public const STATUS_FAILED = 'failed';
    /** @var string Course deleted or check abandoned. */
    public const STATUS_CANCELLED = 'cancelled';
    /** @var int Seconds without changes before a new course is checked automatically, so a burst of edits causes one check. */
    public const AUTO_DELAY = 600;
    /** @var int Default age in days until which a course is checked automatically. */
    public const AUTO_DAYS = 30;
    /** @var int Most automatic checks queued per run of the scheduled task. */
    public const AUTO_PER_RUN = 20;
    /** @var int Seconds without progress after which a running check counts as dead (its task saves progress every 10 links). */
    public const STUCK_AFTER = 2 * HOURSECS;

    /**
     * Whether link checks are enabled on the site (the "Check links" button).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('local_completiondoctor', 'linkcheck');
        return $enabled === false || (bool) $enabled;
    }

    /**
     * Whether new courses are checked automatically. Off unless an administrator turns it on, because
     * it sends requests from the server to every address found in course content.
     *
     * @return bool
     */
    public static function is_auto_enabled(): bool {
        return self::is_enabled() && (bool) get_config('local_completiondoctor', 'linkautonew');
    }

    /**
     * Age in days until which a course counts as new.
     *
     * @return int
     */
    public static function get_auto_days(): int {
        $days = get_config('local_completiondoctor', 'linkautodays');
        return $days === false || $days === '' ? self::AUTO_DAYS : (int) $days;
    }

    /**
     * Queue a link check of a course on request. A waiting automatic check is started now instead.
     *
     * @param int $courseid
     * @param int $userid User who asked for it.
     * @return bool False when a check of this course is already running or requested.
     */
    public static function queue(int $courseid, int $userid): bool {
        self::fail_stuck(null, $courseid);
        $state = self::get_record($courseid);
        if ($state && $state->get('status') === self::STATUS_QUEUED && !$state->get('requestedby')) {
            $state->set_many(['requestedby' => $userid, 'runat' => 0])->update();
            self::schedule($state);
            self::log_request($courseid, (int) $state->get('id'));
            return true;
        }
        if ($state && in_array($state->get('status'), [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }
        $record = self::create($courseid, $userid, 0);
        self::log_request($courseid, (int) $record->get('id'));
        return true;
    }

    /**
     * Mark running checks without progress for STUCK_AFTER seconds as failed: their task died (fatal
     * error, out of memory, cron killed) and will not finish them. Called by the cleanup task, and for
     * one course before a new check of it is refused.
     *
     * @param int|null $now
     * @param int|null $courseid Only this course.
     * @return int Checks marked as failed.
     */
    public static function fail_stuck(?int $now = null, ?int $courseid = null): int {
        global $DB;
        $now = $now ?? time();
        $select = 'status = :running AND timemodified < :since';
        $params = ['running' => self::STATUS_RUNNING, 'since' => $now - self::STUCK_AFTER];
        if ($courseid) {
            $select .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $count = $DB->count_records_select(linkcheck_record::TABLE, $select, $params);
        if ($count) {
            $DB->execute(
                "UPDATE {" . linkcheck_record::TABLE . "}
                    SET status = :failed, timefinished = :now, timemodified = :now2
                  WHERE {$select}",
                $params + ['failed' => self::STATUS_FAILED, 'now' => $now, 'now2' => $now]
            );
        }
        return $count;
    }

    /**
     * Queue automatic checks of new courses whose content changed since their last check and has not
     * changed for AUTO_DELAY seconds (scheduled task).
     *
     * @param int|null $now
     * @return int Checks queued.
     */
    public static function queue_changed_courses(?int $now = null): int {
        global $DB;
        if (!self::is_auto_enabled()) {
            return 0;
        }
        $now = $now ?? time();
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.cacherev
               FROM {course} c
              WHERE c.id <> :siteid AND c.timecreated >= :since AND c.cacherev > 0 AND c.cacherev <= :quiet
                AND NOT EXISTS (SELECT 1 FROM {" . linkcheck_record::TABLE . "} lc
                                 WHERE lc.courseid = c.id
                                   AND (lc.cacherev >= c.cacherev OR lc.status IN (:queued, :running)))
           ORDER BY c.cacherev",
            ['siteid' => SITEID, 'since' => $now - self::get_auto_days() * DAYSECS, 'quiet' => $now - self::AUTO_DELAY,
                'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING],
            0,
            self::AUTO_PER_RUN
        );
        foreach ($courses as $course) {
            self::create((int) $course->id, 0, 0, (int) $course->cacherev);
        }
        return count($courses);
    }

    /**
     * Create the record and the task of a check.
     *
     * @param int $courseid
     * @param int $userid 0 for automatic checks.
     * @param int $runtime Unix time to run at, 0 for the next cron run.
     * @param int|null $cacherev Course cache revision covered, current one when null.
     * @return linkcheck_record
     */
    private static function create(int $courseid, int $userid, int $runtime, ?int $cacherev = null): linkcheck_record {
        global $DB;
        $record = new linkcheck_record(0, (object) [
            'courseid' => $courseid,
            'status' => self::STATUS_QUEUED,
            'requestedby' => $userid,
            'runat' => $runtime > time() ? $runtime : 0,
            'cacherev' => $cacherev ?? (int) $DB->get_field('course', 'cacherev', ['id' => $courseid]),
        ]);
        $record->create();
        self::schedule($record);
        return $record;
    }

    /**
     * Queue (or move) the task of a waiting check.
     *
     * @param linkcheck_record $record
     */
    private static function schedule(linkcheck_record $record): void {
        $task = new check_links();
        $task->set_custom_data(['courseid' => (int) $record->get('courseid'), 'linkcheckid' => (int) $record->get('id')]);
        $runat = (int) $record->get('runat');
        // Just before now, so the next cron run picks a check that should start now.
        $task->set_next_run_time($runat > time() ? $runat : time() - 1);
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Log a requested check.
     *
     * @param int $courseid
     * @param int $linkcheckid
     */
    private static function log_request(int $courseid, int $linkcheckid): void {
        \local_completiondoctor\event\link_check_requested::create([
            'context' => \context_course::instance($courseid),
            'objectid' => $linkcheckid,
        ])->trigger();
    }

    /**
     * The latest check of a course.
     *
     * @param int $courseid
     * @return linkcheck_record|null
     */
    private static function get_record(int $courseid): ?linkcheck_record {
        $records = linkcheck_record::get_records(['courseid' => $courseid], 'id', 'DESC', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * The latest link check of a course.
     *
     * @param int $courseid
     * @return stdClass|null
     */
    public static function get_state(int $courseid): ?stdClass {
        $record = self::get_record($courseid);
        return $record ? $record->to_record() : null;
    }

    /**
     * The latest link check of a course that finished.
     *
     * @param int $courseid
     * @return stdClass|null
     */
    public static function get_last_finished(int $courseid): ?stdClass {
        $records = linkcheck_record::get_records(['courseid' => $courseid, 'status' => self::STATUS_FINISHED], 'id', 'DESC', 0, 1);
        return $records ? reset($records)->to_record() : null;
    }

    /**
     * Get a check by id.
     *
     * @param int $id
     * @return stdClass|null
     */
    public static function get(int $id): ?stdClass {
        $record = linkcheck_record::get_record(['id' => $id]);
        return $record ? $record->to_record() : null;
    }

    /**
     * Update the progress of a link check.
     *
     * @param int $id
     * @param array $fields Fields to set (status, totalitems, processed, skippedfiles, truncated, times).
     */
    public static function update(int $id, array $fields): void {
        $record = linkcheck_record::get_record(['id' => $id]);
        if ($record) {
            $record->set_many($fields)->update();
        }
    }

    /**
     * Delete the checks of a course (course deleted).
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(linkcheck_record::TABLE, ['courseid' => $courseid]);
    }
}
