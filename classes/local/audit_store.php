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

namespace local_chronifycompliance\local;

use local_chronifycompliance\check\audit_result;
use local_chronifycompliance\persistent\audit_record;
use stdClass;

/**
 * Stores the last audit of each course (time, duration, counts of open findings, check errors),
 * whether a new audit was requested, and how many SCORM packages were still waiting for analysis.
 *
 * Courses without findings have no finding rows, so this table is what tells "healthy" apart from
 * "never audited".
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_store {
    /** @var string Audit table. */
    public const TABLE = audit_record::TABLE;

    /**
     * The record of a course, created when missing.
     *
     * @param int $courseid
     * @return audit_record
     */
    private static function record_of(int $courseid): audit_record {
        return audit_record::get_record(['courseid' => $courseid]) ?: new audit_record(0, (object) ['courseid' => $courseid]);
    }

    /**
     * Save a record, creating it when new.
     *
     * @param audit_record $record
     */
    private static function save(audit_record $record): void {
        $record->get('id') ? $record->update() : $record->create();
    }

    /**
     * Record a saved audit. Counts are read from the stored open findings, so ignored ones are excluded.
     * A request made before or in the second the audit started is answered by it: a later one (the
     * course may have changed after the audit read it) stays open for the check queued with it. A
     * request made while a check runs always queues its own check, so the course is checked again.
     *
     * @param audit_result $result
     * @param int|null $now Time the audit started.
     */
    public static function record(audit_result $result, ?int $now = null): void {
        $now = $now ?? $result->timestarted;
        $summary = finding_store::get_summary($result->courseid);
        $record = self::record_of($result->courseid);
        $record->set_many([
            'timeaudited' => $now,
            'duration' => (int) round($result->duration * 1000),
            'queries' => $result->queries,
            'critical' => $summary['critical'],
            'warning' => $summary['warning'],
            'info' => $summary['info'],
            'checkerrors' => $result->errors ? json_encode($result->errors) : null,
            'waiting' => $result->waiting,
        ]);
        if ((int) $record->get('timerequested') <= $now) {
            $record->set('timerequested', 0);
        }
        self::save($record);
    }

    /**
     * Ask for a new audit of a course (answered by a background task).
     *
     * @param int $courseid
     */
    public static function request(int $courseid): void {
        $record = self::record_of($courseid);
        $record->set('timerequested', time());
        self::save($record);
    }

    /**
     * Forget requests whose check never answered them (its task failed out of its attempts or was
     * removed), so the report stops showing "checking" and the first visit can queue a check again.
     *
     * @param int $before Requests made before this time.
     * @return int Requests cleared.
     */
    public static function clear_expired_requests(int $before): int {
        global $DB;
        $select = 'timerequested > 0 AND timerequested < :before';
        $count = $DB->count_records_select(self::TABLE, $select, ['before' => $before]);
        if ($count) {
            $DB->execute(
                "UPDATE {" . self::TABLE . "} SET timerequested = 0, timemodified = :now WHERE {$select}",
                ['before' => $before, 'now' => time()]
            );
        }
        return $count;
    }

    /**
     * Refresh the stored counts after findings were ignored, fixed or reopened outside an audit.
     *
     * @param int $courseid
     */
    public static function refresh_counts(int $courseid): void {
        $record = audit_record::get_record(['courseid' => $courseid]);
        if (!$record) {
            return;
        }
        $summary = finding_store::get_summary($courseid);
        $record->set_many(['critical' => $summary['critical'], 'warning' => $summary['warning'], 'info' => $summary['info']]);
        $record->update();
    }

    /**
     * Refresh the stored counts of every course from the open findings, in one statement per severity
     * (after a check was turned off or its severity changed).
     */
    public static function refresh_all_counts(): void {
        global $DB;
        foreach (['critical' => 3, 'warning' => 2, 'info' => 1] as $field => $severity) {
            $DB->execute(
                "UPDATE {" . self::TABLE . "}
                    SET {$field} = (SELECT COUNT(1) FROM {" . finding_store::TABLE . "} f
                                     WHERE f.courseid = {" . self::TABLE . "}.courseid
                                       AND f.status = :status AND f.severity = :severity)",
                ['status' => 'open', 'severity' => $severity]
            );
        }
    }

    /**
     * The last audit of a course.
     *
     * @param int $courseid
     * @return stdClass|null Null when the course was never audited.
     */
    public static function get(int $courseid): ?stdClass {
        $record = audit_record::get_record(['courseid' => $courseid]);
        return $record && $record->get('timeaudited') ? $record->to_record() : null;
    }

    /**
     * When a new audit was requested and has not run yet.
     *
     * @param int $courseid
     * @return int 0 when none is waiting.
     */
    public static function get_requested(int $courseid): int {
        global $DB;
        return (int) $DB->get_field(self::TABLE, 'timerequested', ['courseid' => $courseid]);
    }

    /**
     * Remember when the course completion settings were saved (event observer).
     *
     * A row may exist before the first audit; it then has timeaudited = 0 and counts as not audited.
     *
     * @param int $courseid
     * @param int $time
     */
    public static function set_criteria_saved(int $courseid, int $time): void {
        $record = self::record_of($courseid);
        $record->set('criteriasaved', $time);
        self::save($record);
    }

    /**
     * When the course completion settings were last saved, as recorded by the plugin.
     *
     * @param int $courseid
     * @return int|null Null when not recorded (saved before the plugin was installed, or never).
     */
    public static function get_criteria_saved(int $courseid): ?int {
        global $DB;
        $time = $DB->get_field(self::TABLE, 'criteriasaved', ['courseid' => $courseid]);
        return $time ? (int) $time : null;
    }

    /**
     * Forget a course (course deleted).
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
