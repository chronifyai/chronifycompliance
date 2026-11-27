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

namespace local_chronifycompliance\availability;

use local_chronifycompliance\check\audit_context;

/**
 * Structural validation of availability (restriction) JSON, without evaluating it for a user.
 *
 * A condition is "broken" when no learner can ever satisfy it: it refers to a deleted activity,
 * grade item, group or grouping, or to an activity without completion tracking. Two conditions are
 * "soft" (a warning, not critical): an empty group, which may fill at enrolment, and an "until" date
 * in the past, which is ignored once the course has ended. A tree is unsatisfiable when its broken
 * conditions make it false for everyone: any broken child of an AND, or all children of an OR.
 * Negated trees and conditions of other plugins (for example availability_profile or third-party
 * conditions) are not judged.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tree_validator {
    /** @var int Time used for date conditions. */
    private int $now;

    /**
     * Create a validator.
     *
     * @param audit_context $context
     * @param int|null $now
     */
    public function __construct(
        /** @var audit_context Audit context. */
        private audit_context $context,
        ?int $now = null,
    ) {
        $this->now = $now ?? time();
    }

    /**
     * Validate an availability JSON string.
     *
     * @param string|null $json Availability of a course module or section.
     * @param int|null $cmid Course module owning the tree (for "previous activity").
     * @param int|null $sectionid Section owning the tree (for "previous activity").
     * @return array{unsatisfiable: bool, reasons: array[]} Reasons have keys type, reason and ref.
     */
    public function validate(?string $json, ?int $cmid = null, ?int $sectionid = null): array {
        if ($json === null || $json === '') {
            return ['unsatisfiable' => false, 'reasons' => []];
        }
        $tree = json_decode($json);
        if (!is_object($tree)) {
            return ['unsatisfiable' => false, 'reasons' => []];
        }
        $reasons = [];
        $false = $this->evaluate($tree, $cmid, $sectionid, $reasons);
        return ['unsatisfiable' => $false, 'reasons' => $false ? $reasons : []];
    }

    /**
     * Evaluate a node.
     *
     * @param object $node Tree or condition.
     * @param int|null $cmid
     * @param int|null $sectionid
     * @param array $reasons Reasons collected for false conditions.
     * @return bool True when the node is false for every learner.
     */
    private function evaluate(object $node, ?int $cmid, ?int $sectionid, array &$reasons): bool {
        if (isset($node->op)) {
            $children = isset($node->c) && is_array($node->c) ? $node->c : [];
            if (!$children || !in_array($node->op, ['&', '|'], true)) {
                return false;
            }
            $results = [];
            $childreasons = [];
            foreach ($children as $child) {
                $local = [];
                $results[] = is_object($child) && $this->evaluate($child, $cmid, $sectionid, $local);
                $childreasons[] = $local;
            }
            if ($node->op === '&') {
                foreach ($results as $i => $result) {
                    if ($result) {
                        array_push($reasons, ...$childreasons[$i]);
                    }
                }
                return in_array(true, $results, true);
            }
            if (!in_array(false, $results, true)) {
                foreach ($childreasons as $local) {
                    array_push($reasons, ...$local);
                }
                return true;
            }
            return false;
        }

        $reason = $this->check_condition($node, $cmid, $sectionid);
        if ($reason !== null) {
            $reasons[] = $reason;
            return true;
        }
        return false;
    }

    /**
     * Check one condition.
     *
     * @param object $condition
     * @param int|null $cmid
     * @param int|null $sectionid
     * @return array|null A reason when the condition can never be met.
     */
    private function check_condition(object $condition, ?int $cmid, ?int $sectionid): ?array {
        $type = $condition->type ?? '';
        switch ($type) {
            case 'completion':
                $ref = (int) ($condition->cm ?? 0);
                if ($ref === -1) {
                    $ref = dependency_graph::get_previous_cmid($this->context, $cmid, $sectionid);
                    if ($ref === null) {
                        return null;
                    }
                }
                $cms = $this->context->get_modinfo()->get_cms();
                if (!isset($cms[$ref]) || $cms[$ref]->deletioninprogress) {
                    return ['type' => 'completion', 'reason' => 'missingcm', 'ref' => $ref];
                }
                $expected = (int) ($condition->e ?? COMPLETION_COMPLETE);
                if ($cms[$ref]->completion == COMPLETION_TRACKING_NONE && $expected !== COMPLETION_INCOMPLETE) {
                    return ['type' => 'completion', 'reason' => 'nocompletion', 'ref' => $ref,
                        'name' => $cms[$ref]->name];
                }
                return null;

            case 'grade':
                $ref = (int) ($condition->id ?? 0);
                if (!isset($this->context->get_grade_items()[$ref])) {
                    return ['type' => 'grade', 'reason' => 'missinggradeitem', 'ref' => $ref];
                }
                return null;

            case 'group':
                if (empty($condition->id)) {
                    return null;
                }
                $ref = (int) $condition->id;
                $groups = $this->get_group_member_counts();
                if (!array_key_exists($ref, $groups)) {
                    return ['type' => 'group', 'reason' => 'missinggroup', 'ref' => $ref];
                }
                if ($groups[$ref] === 0) {
                    return ['type' => 'group', 'reason' => 'emptygroup', 'ref' => $ref, 'soft' => true];
                }
                return null;

            case 'grouping':
                if (empty($condition->id)) {
                    return null;
                }
                $ref = (int) $condition->id;
                if (!array_key_exists($ref, $this->get_groupings())) {
                    return ['type' => 'grouping', 'reason' => 'missinggrouping', 'ref' => $ref];
                }
                return null;

            case 'date':
                if ($this->course_ended()) {
                    return null;
                }
                if (($condition->d ?? '') === '<' && isset($condition->t) && (int) $condition->t <= $this->now) {
                    return ['type' => 'date', 'reason' => 'datepassed', 'ref' => (int) $condition->t,
                        'date' => ['date' => (int) $condition->t], 'soft' => true];
                }
                return null;
        }
        return null;
    }

    /**
     * Whether the course has an end date that has passed: closing dates are then expected.
     *
     * @return bool
     */
    public function course_ended(): bool {
        $enddate = (int) ($this->context->course->enddate ?? 0);
        return $enddate > 0 && $enddate <= $this->now;
    }

    /**
     * Time used for date conditions.
     *
     * @return int
     */
    public function get_now(): int {
        return $this->now;
    }

    /**
     * Member counts of the course groups.
     *
     * @return int[] Keyed by group id.
     */
    private function get_group_member_counts(): array {
        return $this->context->remember('groupmembercounts', function (audit_context $context): array {
            global $DB;
            $counts = $DB->get_records_sql_menu(
                "SELECT g.id, COUNT(gm.id)
                   FROM {groups} g
              LEFT JOIN {groups_members} gm ON gm.groupid = g.id
                  WHERE g.courseid = :courseid
               GROUP BY g.id",
                ['courseid' => $context->course->id]
            );
            return array_map('intval', $counts);
        });
    }

    /**
     * Groupings of the course.
     *
     * @return array Keyed by grouping id.
     */
    private function get_groupings(): array {
        return $this->context->remember('groupings', function (audit_context $context): array {
            global $DB;
            return $DB->get_records_menu('groupings', ['courseid' => $context->course->id], '', 'id, name');
        });
    }
}
