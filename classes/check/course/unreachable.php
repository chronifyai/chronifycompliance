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

use local_completiondoctor\availability\tree_validator;
use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;

/**
 * A required activity cannot be reached by a normal learner: it or its section is hidden, or its
 * restrictions can never be met (deleted activity, grade item, group or grouping, empty group,
 * activity without completion, "until" date in the past).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unreachable extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'unreachable';
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
        $modinfo = $context->get_modinfo();
        $cms = $modinfo->get_cms();
        $validator = new tree_validator($context);
        $findings = [];

        foreach (array_keys($this->get_required_cms($context)) as $cmid) {
            if (!isset($cms[$cmid]) || $cms[$cmid]->deletioninprogress) {
                continue;
            }
            $cm = $cms[$cmid];
            $reasons = [];
            $critical = false;

            if (!$cm->visible) {
                $reasons[] = ['str' => 'reason:hidden'];
                $critical = true;
            } else if (!$cm->visibleoncoursepage) {
                $reasons[] = ['str' => 'reason:stealth'];
            }

            $trees = [$validator->validate($cm->availability, (int) $cm->id, null)];
            foreach ($this->get_sections($modinfo, $cm) as $depth => $section) {
                if (!$section->visible) {
                    $reasons[] = ['str' => $depth ? 'reason:parenthidden' : 'reason:sectionhidden'];
                    $critical = true;
                }
                $trees[] = $validator->validate($section->availability, null, (int) $section->id);
            }
            foreach ($trees as $tree) {
                if ($tree['unsatisfiable']) {
                    foreach ($tree['reasons'] as $reason) {
                        $critical = $critical || empty($reason['soft']);
                        $reasons[] = ['str' => 'reason:' . $reason['reason'], 'a' => $reason];
                    }
                }
            }

            $closed = $this->get_close_date($cm);
            if ($closed && $closed <= $validator->get_now() && !$validator->course_ended()) {
                $reasons[] = ['str' => 'reason:closed', 'a' => ['date' => ['date' => $closed]]];
            }

            if ($reasons) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:unreachable:message',
                    $this->cm_params($cm) + ['reasons' => $reasons],
                    ['reasons' => $reasons],
                    (int) $cmid,
                    [],
                    $critical ? $this->get_severity() : self::SEVERITY_WARNING
                );
            }
        }
        return $findings;
    }

    /**
     * The section of an activity, then the sections holding it in Moodle 5 subsections (outermost last).
     *
     * @param \course_modinfo $modinfo
     * @param \cm_info $cm
     * @return \section_info[]
     */
    private function get_sections(\course_modinfo $modinfo, \cm_info $cm): array {
        $sections = [];
        $section = $modinfo->get_section_info_by_id($cm->section);
        while ($section && count($sections) < 5) {
            $sections[] = $section;
            $delegate = $section->is_delegated() ? $section->get_component_instance() : null;
            if (!$delegate instanceof \core_courseformat\sectiondelegatemodule) {
                break;
            }
            try {
                $parent = $delegate->get_cm();
            } catch (\Throwable $e) {
                break;
            }
            $section = $modinfo->get_section_info_by_id($parent->section);
        }
        return $sections;
    }

    /**
     * Date after which learners can no longer finish the activity: quiz, SCORM, choice and feedback
     * close date, assignment and forum cut-off date. Other activity types have none here.
     *
     * @param \cm_info $cm
     * @return int 0 when there is none.
     */
    private function get_close_date(\cm_info $cm): int {
        $customdata = (array) $cm->customdata;
        $field = match ($cm->modname) {
            'quiz', 'scorm', 'choice', 'feedback' => 'timeclose',
            'assign', 'forum' => 'cutoffdate',
            default => null,
        };
        return $field ? (int) ($customdata[$field] ?? 0) : 0;
    }
}
