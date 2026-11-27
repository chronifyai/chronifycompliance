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

namespace local_completiondoctor\local;

use local_completiondoctor\persistent\scan_record;
use local_completiondoctor\task\audit_course;
use stdClass;

/**
 * Queues course audits as ad-hoc tasks and tracks scan progress.
 *
 * Course ids are read with a recordset and queued in batches, so a scan of any size never holds
 * all courses in memory. Each batch task updates the progress counters of its scan row.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_manager {
    /** @var string Scan table. */
    public const TABLE = scan_record::TABLE;
    /** @var string Scan is queued. */
    public const STATUS_QUEUED = 'queued';
    /** @var string Some batches are done. */
    public const STATUS_RUNNING = 'running';
    /** @var string All batches are done. */
    public const STATUS_FINISHED = 'finished';
    /** @var string Scan was cancelled; its batches skip their remaining courses. */
    public const STATUS_CANCELLED = 'cancelled';
    /** @var int Default number of courses per ad-hoc task. */
    public const DEFAULT_BATCH_SIZE = 20;
    /** @var int Seconds an event-triggered re-audit waits, so several edits cause one audit. */
    public const REAUDIT_DELAY = 300;
    /** @var int Default seconds between the start of two scan batches. */
    public const DEFAULT_PACE = 30;
    /** @var int Seconds a paused task waits before it checks again. */
    public const PAUSE_RETRY = HOURSECS;
    /** @var int Seconds without activity after which a scan counts as stuck (a postponed batch counts as activity). */
    public const STUCK_AFTER = 6 * HOURSECS;

    /**
     * Whether an administrator paused all scans and package analysis.
     *
     * @return bool
     */
    public static function is_paused(): bool {
        return (bool) get_config('local_completiondoctor', 'paused');
    }

    /**
     * Seconds between the start of two batches.
     *
     * @return int
     */
    public static function get_pace(): int {
        $pace = get_config('local_completiondoctor', 'scanpace');
        return $pace === false || $pace === '' ? self::DEFAULT_PACE : max(0, (int) $pace);
    }

    /**
     * Queue an audit of many courses.
     *
     * @param int $userid User starting the scan (0 for the system).
     * @param int|null $categoryid Only courses in this category and its subcategories.
     * @param int|null $limit Maximum number of courses.
     * @param string[]|null $checkids Only these checks.
     * @return int Scan id.
     */
    public static function start_scan(
        int $userid,
        ?int $categoryid = null,
        ?int $limit = null,
        ?array $checkids = null
    ): int {
        global $DB;
        $batchsize = (int) get_config('local_completiondoctor', 'scanbatchsize') ?: self::DEFAULT_BATCH_SIZE;
        $scan = new scan_record(0, (object) [
            'type' => $categoryid ? 'category' : 'site',
            'status' => self::STATUS_QUEUED,
            'batchsize' => $batchsize,
            'params' => json_encode(['categoryid' => $categoryid, 'limit' => $limit, 'checkids' => $checkids]),
            'startedby' => $userid,
            'timestarted' => time(),
        ]);
        $scanid = (int) $scan->create()->get('id');

        [$sql, $sqlparams] = self::get_course_ids_sql($categoryid);
        $recordset = $DB->get_recordset_sql($sql, $sqlparams, 0, (int) $limit);
        // Batches start one "pace" apart, so a scan never keeps more than one task runner busy for long.
        $pace = self::get_pace();
        $starttime = time();
        $batch = [];
        $batches = 0;
        $total = 0;
        $lastcourseid = 0;
        foreach ($recordset as $record) {
            $batch[] = (int) $record->id;
            $lastcourseid = (int) $record->id;
            if (count($batch) >= $batchsize) {
                self::queue_batch($scanid, $batch, $checkids, $starttime + $batches * $pace, $batches);
                $batches++;
                $total += count($batch);
                $batch = [];
            }
        }
        $recordset->close();
        if ($batch) {
            self::queue_batch($scanid, $batch, $checkids, $starttime + $batches * $pace, $batches);
            $total += count($batch);
        }

        $scan->set_many([
            'totalitems' => $total,
            'lastcourseid' => $lastcourseid,
            'status' => $total ? self::STATUS_QUEUED : self::STATUS_FINISHED,
            'timefinished' => $total ? null : time(),
        ])->update();
        if ($userid) {
            \local_completiondoctor\event\scan_started::create(['context' => \context_system::instance(),
                'objectid' => $scanid])->trigger();
        }
        return $scanid;
    }

    /**
     * Queue a delayed re-audit of one course. Repeated calls before it starts do not add tasks; a call
     * while the re-audit is already running queues a new one, so later edits are audited too.
     *
     * @param int $courseid
     */
    public static function queue_course(int $courseid): void {
        global $DB;
        $task = new audit_course();
        $task->set_custom_data(['courseids' => [$courseid], 'scanid' => null, 'checkids' => null]);
        $task->set_next_run_time(time() + self::REAUDIT_DELAY);
        $waiting = $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND timestarted IS NULL AND ' . $DB->sql_compare_text('customdata') . ' = '
                . $DB->sql_compare_text(':customdata'),
            ['classname' => '\\' . audit_course::class, 'customdata' => $task->get_custom_data_as_string()]
        );
        if (!$waiting) {
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Add the results of a finished batch to the scan counters.
     *
     * @param int $scanid
     * @param int $processed Courses audited.
     * @param int $failed Courses that failed.
     */
    public static function record_progress(int $scanid, int $processed, int $failed): void {
        global $DB;
        $now = time();
        $DB->execute(
            "UPDATE {" . self::TABLE . "}
                SET processed = processed + :processed, failed = failed + :failed, status = :running, timemodified = :now
              WHERE id = :id AND status IN (:queued, :running2)",
            ['processed' => $processed, 'failed' => $failed, 'running' => self::STATUS_RUNNING, 'id' => $scanid,
                'queued' => self::STATUS_QUEUED, 'running2' => self::STATUS_RUNNING, 'now' => $now]
        );
        $DB->execute(
            "UPDATE {" . self::TABLE . "}
                SET status = :finished, timefinished = :now
              WHERE id = :id AND status = :running AND processed + failed >= totalitems",
            ['finished' => self::STATUS_FINISHED, 'now' => $now, 'id' => $scanid, 'running' => self::STATUS_RUNNING]
        );
    }

    /**
     * Mark a scan as alive without progress (a batch postponed itself while scans are paused), so a
     * long pause does not make it look stuck.
     *
     * @param int $scanid
     */
    public static function touch(int $scanid): void {
        global $DB;
        $DB->execute(
            "UPDATE {" . self::TABLE . "} SET timemodified = :now WHERE id = :id AND status IN (:queued, :running)",
            ['now' => time(), 'id' => $scanid, 'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING]
        );
    }

    /**
     * Cancel a scan. Its waiting batches end without auditing when they run, and a batch already
     * running stops before its next course.
     *
     * @param int $scanid
     * @param bool $log Write the cancellation to Moodle's log (a person cancelled it).
     */
    public static function cancel(int $scanid, bool $log = true): void {
        $scan = scan_record::get_record(['id' => $scanid]);
        if (!$scan) {
            return;
        }
        $scan->set_many(['status' => self::STATUS_CANCELLED, 'timefinished' => time()])->update();
        if ($log) {
            \local_completiondoctor\event\scan_cancelled::create(['context' => \context_system::instance(),
                'objectid' => $scanid])->trigger();
        }
    }

    /**
     * Cancel scans without activity for STUCK_AFTER seconds (their batch task died). Called by the
     * cleanup task. Nothing is cancelled while scans are paused.
     *
     * @param int|null $now
     * @return int Scans cancelled.
     */
    public static function cancel_stuck(?int $now = null): int {
        global $DB;
        if (self::is_paused()) {
            return 0;
        }
        $now = $now ?? time();
        $ids = $DB->get_fieldset_select(
            self::TABLE,
            'id',
            'status IN (:queued, :running) AND timemodified < :since',
            ['queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING, 'since' => $now - self::STUCK_AFTER]
        );
        foreach ($ids as $id) {
            self::cancel((int) $id, false);
        }
        return count($ids);
    }

    /**
     * Get a scan.
     *
     * @param int $scanid
     * @return stdClass|null
     */
    public static function get_scan(int $scanid): ?stdClass {
        $scan = scan_record::get_record(['id' => $scanid]);
        return $scan ? $scan->to_record() : null;
    }

    /**
     * The most recent scan.
     *
     * @return stdClass|null
     */
    public static function get_latest_scan(): ?stdClass {
        $records = scan_record::get_records([], 'id', 'DESC', 0, 1);
        return $records ? reset($records)->to_record() : null;
    }

    /**
     * The most recent scan that is queued or running.
     *
     * @return stdClass|null
     */
    public static function get_active_scan(): ?stdClass {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'status IN (:queued, :running)',
            ['queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING],
            'id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * SQL selecting the ids of the courses to scan, in id order.
     *
     * @param int|null $categoryid Only this category and its subcategories.
     * @return array [sql, params]
     */
    public static function get_course_ids_sql(?int $categoryid): array {
        global $DB;
        $params = ['siteid' => SITEID];
        $where = 'c.id <> :siteid';
        if ($categoryid) {
            $where .= ' AND (cc.id = :categoryid OR ' . $DB->sql_like('cc.path', ':path') . ')';
            $params['categoryid'] = $categoryid;
            $params['path'] = $DB->get_field('course_categories', 'path', ['id' => $categoryid], MUST_EXIST) . '/%';
        }
        $sql = "SELECT c.id
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE {$where}
              ORDER BY c.id";
        return [$sql, $params];
    }

    /**
     * Queue one batch task.
     *
     * @param int $scanid
     * @param int[] $courseids
     * @param string[]|null $checkids
     * @param int $runtime Earliest time the batch runs.
     * @param int $index Position of the batch in the scan, to keep the spacing after a pause.
     */
    private static function queue_batch(int $scanid, array $courseids, ?array $checkids, int $runtime, int $index): void {
        $task = new audit_course();
        $task->set_custom_data(['courseids' => $courseids, 'scanid' => $scanid, 'checkids' => $checkids, 'batch' => $index]);
        $task->set_next_run_time($runtime);
        \core\task\manager::queue_adhoc_task($task);
    }
}
