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

namespace local_completiondoctor\fix;

use local_completiondoctor\check\finding;
use local_completiondoctor\persistent\fixlog_record;
use stdClass;

/**
 * Log of every change made by a fix, with the values needed to undo it.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fix_log {
    /** @var string Fix log table. */
    public const TABLE = fixlog_record::TABLE;

    /**
     * Record an applied fix.
     *
     * @param finding $finding
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param int $userid
     * @return int Fix log id.
     */
    public static function record(finding $finding, string $fixtype, array $before, array $after, int $userid): int {
        $record = new fixlog_record(0, (object) [
            'findingid' => $finding->id,
            'courseid' => $finding->courseid,
            'cmid' => $finding->cmid,
            'checkid' => $finding->checkid,
            'fixtype' => $fixtype,
            'userid' => $userid,
            'valuebefore' => json_encode($before),
            'valueafter' => json_encode($after),
        ]);
        return (int) $record->create()->get('id');
    }

    /**
     * Get a fix log record.
     *
     * @param int $id
     * @return stdClass
     */
    public static function get(int $id): stdClass {
        return (new fixlog_record($id))->to_record();
    }

    /**
     * Latest fix of a finding that has not been undone.
     *
     * @param int $findingid
     * @return stdClass|null
     */
    public static function get_latest_active(int $findingid): ?stdClass {
        global $DB;
        $records = $DB->get_records(self::TABLE, ['findingid' => $findingid, 'undone' => 0], 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Mark a fix as undone.
     *
     * @param int $id
     * @param int $userid
     */
    public static function mark_undone(int $id, int $userid): void {
        (new fixlog_record($id))->set_many(['undone' => 1, 'timeundone' => time(), 'undoneby' => $userid])->update();
    }

    /**
     * Delete the fix log of a course (course deleted), so no user id stays out of the privacy API's reach.
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
