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

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;
use local_completiondoctor\fix\criteria_editor;
use local_completiondoctor\fix\fix_preview;
use local_completiondoctor\fix\fix_result;

/**
 * A passing grade is required but no pass grade is set.
 *
 * Activity: "receive a passing grade" with grade to pass 0 completes on any grade (core treats a
 * missing pass mark as "any grade"). Course grade criterion without a pass value never completes.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nopassgrade extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'nopassgrade';
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
        $findings = [];
        foreach ($this->get_tracked_cms($context) as $cmid => $cm) {
            if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC || empty($cm->completionpassgrade)) {
                continue;
            }
            $item = $this->get_completion_grade_item($context, $cm);
            if ($item && (float) $item->gradepass <= 0) {
                $findings[] = $this->make_finding(
                    $courseid,
                    'check:nopassgrade:message',
                    $this->cm_params($cm),
                    ['gradeitemid' => (int) $item->id, 'grademax' => (float) $item->grademax],
                    (int) $cmid
                );
            }
        }
        foreach ($context->get_criteria(COMPLETION_CRITERIA_TYPE_GRADE) as $criterion) {
            if ((float) $criterion->gradepass <= 0) {
                $courseitem = \grade_item::fetch_course_item($courseid);
                $findings[] = $this->make_finding(
                    $courseid,
                    'check:nopassgrade:messagecourse',
                    [],
                    ['criterionid' => (int) $criterion->id, 'grademax' => (float) $courseitem->grademax],
                    null,
                    ['criterionid' => (int) $criterion->id]
                );
            }
        }
        return $findings;
    }

    /**
     * Always fixable with a pass grade.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return true;
    }

    /**
     * The fix needs the pass grade.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_fix_parameters(finding $finding): array {
        $max = (float) ($finding->details['grademax'] ?? 100);
        return [[
            'name' => 'gradepass',
            'type' => PARAM_FLOAT,
            'label' => get_string('gradepass', 'grades'),
            'default' => round($max / 2, 2),
        ]];
    }

    /**
     * Preview setting the pass grade.
     *
     * @param finding $finding
     * @param array $params Must contain gradepass.
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $gradepass = clean_param($params['gradepass'] ?? '', PARAM_FLOAT);
        $max = (float) ($finding->details['grademax'] ?? 0);
        if ($gradepass === null || $gradepass <= 0 || ($max > 0 && $gradepass > $max)) {
            throw new \invalid_parameter_exception(get_string('fix:error:gradepass', 'local_completiondoctor', $max));
        }
        if (isset($finding->details['criterionid'])) {
            $current = (float) criteria_editor::get_grade_criterion($finding->courseid);
            $preview = new fix_preview(
                'criteriongradepass',
                ['courseid' => $finding->courseid, 'gradepass' => $current],
                ['courseid' => $finding->courseid, 'gradepass' => $gradepass],
                ['gradepass' => $gradepass]
            );
            $label = get_string('coursegrade', 'completion');
        } else {
            $item = \grade_item::fetch(['id' => $finding->details['gradeitemid']]);
            if (!$item) {
                throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
            }
            $preview = new fix_preview(
                'itemgradepass',
                ['gradeitemid' => (int) $item->id, 'gradepass' => (float) $item->gradepass],
                ['gradeitemid' => (int) $item->id, 'gradepass' => $gradepass],
                ['gradepass' => $gradepass]
            );
            $label = get_string('gradepass', 'grades') . ': ' . ($finding->messageparams['name'] ?? '');
        }
        return $preview->add_row($label, format_float($preview->before['gradepass'], 2), format_float($gradepass, 2));
    }

    /**
     * Set the pass grade.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $this->set_gradepass($preview->fixtype, $preview->after);
        return new fix_result(true, $preview->after);
    }

    /**
     * Restore the previous pass grade.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        $before += ['courseid' => (int) $fixlog->courseid];
        $this->assert_unchanged(['gradepass' => $this->get_gradepass($fixtype, $before)], ['gradepass' => $after['gradepass']]);
        $this->set_gradepass($fixtype, $before);
        return new fix_result(true, $before);
    }

    /**
     * Current grade to pass: of the course grade criterion, or of a grade item.
     *
     * @param string $fixtype criteriongradepass or itemgradepass.
     * @param array $values
     * @return float|null
     */
    private function get_gradepass(string $fixtype, array $values): ?float {
        if ($fixtype === 'criteriongradepass') {
            return criteria_editor::get_grade_criterion((int) $values['courseid']);
        }
        $item = \grade_item::fetch(['id' => $values['gradeitemid']]);
        return $item ? (float) $item->gradepass : null;
    }

    /**
     * Write a grade to pass: of the course grade criterion, or of a grade item.
     *
     * @param string $fixtype criteriongradepass or itemgradepass.
     * @param array $values
     */
    private function set_gradepass(string $fixtype, array $values): void {
        if ($fixtype === 'criteriongradepass') {
            criteria_editor::set_grade_criterion((int) $values['courseid'], (float) $values['gradepass']);
            return;
        }
        $item = \grade_item::fetch(['id' => $values['gradeitemid']]);
        if (!$item) {
            throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
        }
        $item->gradepass = $values['gradepass'];
        $item->update('local_completiondoctor');
    }
}
