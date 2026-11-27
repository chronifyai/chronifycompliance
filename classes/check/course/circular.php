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

namespace local_completiondoctor\check\course;

use local_completiondoctor\availability\dependency_graph;
use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;

/**
 * Circular dependencies: activities whose restrictions require each other's completion, or
 * courses that are each other's completion prerequisite.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circular extends course_check {
    /** @var int Maximum depth followed in the prerequisite course chain. */
    private const MAX_COURSE_DEPTH = 20;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'circular';
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
        $courseid = (int) $context->course->id;
        $cms = $context->get_modinfo()->get_cms();
        $findings = [];

        foreach (dependency_graph::find_cycles(dependency_graph::build($context)) as $cycle) {
            $names = array_map(fn(int $cmid) => isset($cms[$cmid]) ? $cms[$cmid]->name : (string) $cmid, $cycle);
            $findings[] = $this->make_finding(
                $courseid,
                'check:circular:message',
                ['names' => implode(' → ', $names)],
                ['cmids' => $cycle],
                $cycle[0],
                ['cycle' => implode(',', $cycle)]
            );
        }

        $coursecycle = $this->find_course_cycle($context, $courseid);
        if ($coursecycle) {
            $findings[] = $this->make_finding(
                $courseid,
                'check:circular:messagecourses',
                ['courses' => ['courses' => $coursecycle]],
                ['courseids' => $coursecycle],
                null,
                ['coursecycle' => implode(',', $coursecycle)]
            );
        }
        return $findings;
    }

    /**
     * Follow course prerequisites from a course and return a path that leads back to it.
     *
     * @param audit_context $context
     * @param int $courseid
     * @return int[]|null Course ids along the cycle, starting and ending with $courseid.
     */
    private function find_course_cycle(audit_context $context, int $courseid): ?array {
        // All course prerequisites in one query (a small set), read once per audit.
        $edges = $context->remember('circular:courseprerequisites', function (): array {
            global $DB;
            $edges = [];
            $rows = $DB->get_recordset(
                'course_completion_criteria',
                ['criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE],
                '',
                'id, course, courseinstance'
            );
            foreach ($rows as $row) {
                $edges[(int) $row->course][] = (int) $row->courseinstance;
            }
            $rows->close();
            return $edges;
        });
        $visited = [];
        $stack = [[$courseid, [$courseid]]];
        while ($stack) {
            [$current, $path] = array_pop($stack);
            if (count($path) > self::MAX_COURSE_DEPTH) {
                continue;
            }
            foreach ($edges[$current] ?? [] as $next) {
                if ($next === $courseid) {
                    return array_merge($path, [$courseid]);
                }
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $stack[] = [$next, array_merge($path, [$next])];
                }
            }
        }
        return null;
    }
}
