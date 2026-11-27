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
 * "Manual completion by others" uses a role that no active user has in the course (or above it), or a
 * role that is not allowed to mark learners complete (moodle/course:markcomplete), so no one can.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manualrole extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'manualrole';
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
        global $DB;
        $criteria = $context->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE);
        if (!$criteria) {
            return [];
        }
        $coursecontext = \context_course::instance($context->course->id);
        [$insql, $params] = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED);

        $findings = [];
        foreach ($criteria as $criterion) {
            $roleid = (int) $criterion->role;
            $identity = ['criterionid' => (int) $criterion->id];
            $role = $DB->get_record('role', ['id' => $roleid]);
            if (!$role) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:manualrole:messagemissing',
                    ['id' => $roleid],
                    ['roleid' => $roleid],
                    null,
                    $identity
                );
                continue;
            }
            [$allowed, $forbidden] = $this->get_markers($coursecontext);
            if (!in_array($roleid, $allowed, true) || in_array($roleid, $forbidden, true)) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:manualrole:messagenocap',
                    ['role' => ['role' => $roleid, 'contextid' => (int) $coursecontext->id]],
                    ['roleid' => $roleid],
                    null,
                    $identity
                );
                continue;
            }
            $count = $DB->count_records_sql(
                "SELECT COUNT(ra.id)
                   FROM {role_assignments} ra
                   JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                  WHERE ra.roleid = :roleid AND ra.contextid {$insql}",
                ['roleid' => $roleid] + $params
            );
            if (!$count) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:manualrole:message',
                    ['role' => ['role' => $roleid, 'contextid' => (int) $coursecontext->id]],
                    ['roleid' => $roleid],
                    null,
                    $identity
                );
            }
        }
        return $findings;
    }

    /**
     * Roles allowed and forbidden to mark learners complete in the course.
     *
     * @param \context_course $coursecontext
     * @return array{0: int[], 1: int[]} Allowed and forbidden role ids.
     */
    private function get_markers(\context_course $coursecontext): array {
        [$allowed, $forbidden] = get_roles_with_cap_in_context($coursecontext, 'moodle/course:markcomplete');
        return [array_map('intval', array_keys($allowed)), array_map('intval', array_keys($forbidden))];
    }
}
