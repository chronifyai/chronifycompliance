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

namespace local_chronifycompliance\links;

use stdClass;

/**
 * Stores the links found by the last link check of each course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_store {
    /** @var string Link table. */
    public const TABLE = 'local_chronifycompliance_link';

    /**
     * Replace the links of a course with the result of a new check.
     *
     * @param int $courseid
     * @param array[] $rows Rows without courseid.
     */
    public static function replace_course(int $courseid, array $rows): void {
        global $DB, $USER;
        // Inserted in bulk (a course can have thousands of links), so the persistent fields are set here.
        $stamps = ['courseid' => $courseid, 'timecreated' => time(), 'timemodified' => time(),
            'usermodified' => (int) ($USER->id ?? 0)];
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
        $records = [];
        foreach ($rows as $row) {
            $records[] = (object) ($row + $stamps);
        }
        if ($records) {
            $DB->insert_records(self::TABLE, $records);
        }
        $transaction->allow_commit();
    }

    /**
     * A recent result of an external URL from any course.
     *
     * @param string $urlhash sha1 of the URL.
     * @param int $since Oldest check time accepted.
     * @return stdClass|null With status, httpcode, reason and timechecked.
     */
    public static function get_recent(string $urlhash, int $since): ?stdClass {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'urlhash = :urlhash AND timechecked >= :since AND kind = :kind AND status <> :skipped',
            ['urlhash' => $urlhash, 'since' => $since, 'kind' => link_checker::KIND_EXTERNAL,
                'skipped' => link_checker::STATUS_SKIPPED],
            'timechecked DESC',
            'id, status, httpcode, reason, timechecked',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Links of a course with the given statuses.
     *
     * @param int $courseid
     * @param int[] $statuses
     * @param int $limit
     * @return stdClass[]
     */
    public static function get_course_links(int $courseid, array $statuses, int $limit = 0): array {
        global $DB;
        if (!$statuses) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        return $DB->get_records_select(
            self::TABLE,
            "courseid = :courseid AND status {$insql}",
            $params,
            'status, cmid, source, id',
            '*',
            0,
            $limit
        );
    }

    /**
     * Number of links of a course by status.
     *
     * @param int $courseid
     * @return int[] Keyed by status.
     */
    public static function count_by_status(int $courseid): array {
        global $DB;
        $counts = array_fill_keys([link_checker::STATUS_OK, link_checker::STATUS_BROKEN,
            link_checker::STATUS_UNVERIFIED, link_checker::STATUS_SKIPPED], 0);
        $records = $DB->get_records_sql(
            "SELECT status, COUNT(1) AS total FROM {" . self::TABLE . "} WHERE courseid = :courseid GROUP BY status",
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $counts[(int) $record->status] = (int) $record->total;
        }
        return $counts;
    }

    /**
     * Delete the links of a course.
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
