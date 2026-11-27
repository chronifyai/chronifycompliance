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

namespace local_chronifycompliance\check\completion;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_check;
use local_chronifycompliance\check\finding;

/**
 * A prerequisite course of the completion criteria is deleted, has completion off, or has open
 * critical findings of its own.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prereqbroken extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'prereqbroken';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB, $CFG;
        $findings = [];
        $criteria = $context->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE);
        if (!$criteria) {
            return [];
        }
        // The prerequisite courses and whether they have criteria, in two queries.
        $targetids = array_unique(array_map(fn($c) => (int) $c->courseinstance, $criteria));
        $targets = $DB->get_records_list('course', 'id', $targetids, '', 'id, fullname, enablecompletion');
        [$insql, $params] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED);
        $withcriteria = $DB->get_records_sql_menu(
            "SELECT course, COUNT(1) FROM {course_completion_criteria} WHERE course {$insql} GROUP BY course",
            $params
        );
        foreach ($criteria as $criterion) {
            $targetid = (int) $criterion->courseinstance;
            $target = $targets[$targetid] ?? null;
            $identity = ['criterionid' => (int) $criterion->id];
            $details = ['prereqcourseid' => $targetid];
            if (!$target) {
                $key = 'check:prereqbroken:messagemissing';
                $params = ['id' => $targetid];
            } else if (empty($target->enablecompletion) || empty($CFG->enablecompletion)) {
                $key = 'check:prereqbroken:messagenotracking';
                $params = ['name' => ['course' => $targetid]];
            } else if (empty($withcriteria[$targetid])) {
                // Only facts about the prerequisite itself, never other audits' findings: the result
                // must not depend on the order in which courses are audited.
                $key = 'check:prereqbroken:messagenocriteria';
                $params = ['name' => ['course' => $targetid]];
            } else {
                continue;
            }
            $findings[] = $this->make_finding((int) $context->course->id, $key, $params, $details, null, $identity);
        }
        return $findings;
    }
}
