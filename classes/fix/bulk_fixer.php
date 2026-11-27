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

namespace local_chronifycompliance\fix;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\finding_store;

/**
 * Fix all one click problems of many courses: completion and SCORM findings whose fix needs no input.
 *
 * The preview lists every change; apply only fixes findings that were in the preview. Each fix is its own
 * transaction and fix log entry, so each can be undone. Security findings are never fixed in bulk.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_fixer {
    /** @var int Most findings fixed in one go. */
    public const MAX = 200;
    /** @var string[] Packs that can be fixed in bulk. */
    public const PACKS = [base_check::PACK_COMPLETION, base_check::PACK_SCORM];

    /**
     * Open one click findings of the courses that the user may fix, and how many fixable ones need input.
     *
     * @param int[] $courseids
     * @return array [finding[] one click findings (at most MAX), int findings that need a decision, int over MAX]
     */
    public static function candidates(array $courseids): array {
        global $DB;
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids), fn($id) => $id !== (int) SITEID)));
        if (!$courseids) {
            return [[], 0, 0];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['open'] = finding::STATUS_OPEN;
        $records = $DB->get_records_select(
            'local_chronifycompliance_finding',
            "courseid {$insql} AND status = :open AND fixable = 1",
            $params,
            'severity DESC, courseid, id'
        );
        $allowed = [];
        $found = [];
        $needsinput = 0;
        $over = 0;
        foreach ($records as $record) {
            $courseid = (int) $record->courseid;
            if (!isset($allowed[$courseid])) {
                $context = \context_course::instance($courseid, IGNORE_MISSING);
                $allowed[$courseid] = $context && has_capability('local/chronifycompliance:applyfix', $context);
            }
            $check = check_registry::get_check($record->checkid);
            if (!$allowed[$courseid] || !$check || !$check->is_enabled() || !in_array($check->get_pack(), self::PACKS, true)) {
                continue;
            }
            $finding = finding::from_record($record);
            if (!$check->can_fix($finding)) {
                continue;
            }
            if ($check->get_fix_parameters($finding)) {
                $needsinput++;
            } else if (count($found) < self::MAX) {
                $found[] = $finding;
            } else {
                $over++;
            }
        }
        return [$found, $needsinput, $over];
    }

    /**
     * Preview every one click fix of the courses. Writes nothing.
     *
     * @param int[] $courseids
     * @return array Keys items (findingid, courseid, coursename, checkname, message, rows, warnings, error),
     *               courses, needsinput, over.
     */
    public static function preview(array $courseids): array {
        [$findings, $needsinput, $over] = self::candidates($courseids);
        $items = [];
        $names = [];
        foreach ($findings as $finding) {
            $courseid = (int) $finding->courseid;
            $names[$courseid] = $names[$courseid] ?? format_string(get_course($courseid)->fullname);
            $item = [
                'findingid' => (int) $finding->id,
                'courseid' => $courseid,
                'coursename' => $names[$courseid],
                'checkname' => check_registry::get_check($finding->checkid)->get_name(),
                'message' => $finding->get_message(),
                'rows' => [],
                'warnings' => [],
                'error' => '',
            ];
            try {
                $preview = fixer::preview((int) $finding->id);
                $item['rows'] = $preview->rows;
                $item['warnings'] = $preview->warnings;
            } catch (\moodle_exception | \coding_exception $e) {
                $item['error'] = $e->getMessage();
            }
            $items[] = $item;
        }
        return [
            'items' => $items,
            'courses' => count($names),
            'needsinput' => $needsinput,
            'over' => $over,
        ];
    }

    /**
     * Apply the one click fixes of the courses that were in the preview.
     *
     * @param int[] $courseids
     * @param int[] $findingids Findings shown in the preview.
     * @param int $userid
     * @return array Keys fixed, failed, fixlogids, courses.
     */
    public static function apply(array $courseids, array $findingids, int $userid): array {
        [$findings] = self::candidates($courseids);
        $wanted = array_flip(array_map('intval', $findingids));
        $fixlogids = [];
        $failed = 0;
        $courses = [];
        foreach ($findings as $finding) {
            if (!isset($wanted[(int) $finding->id])) {
                continue;
            }
            try {
                $result = fixer::apply((int) $finding->id, [], $userid);
            } catch (\moodle_exception | \coding_exception $e) {
                $failed++;
                continue;
            }
            if ($result->success) {
                $fixlogids[] = (int) $result->fixlogid;
                $courses[(int) $finding->courseid] = true;
            } else {
                $failed++;
            }
        }
        return ['fixed' => count($fixlogids), 'failed' => $failed, 'fixlogids' => $fixlogids, 'courses' => count($courses)];
    }
}
