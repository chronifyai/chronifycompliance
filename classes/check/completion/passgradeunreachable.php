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
use local_chronifycompliance\check\finding;

/**
 * The grade needed for completion can never be reached: the grade to pass is higher than the maximum
 * grade (Moodle does not check this again when the maximum grade is lowered later), or a quiz has no
 * questions with marks, so nobody gets a grade at all. The fix (set the grade to pass) is the one of
 * nopassgrade.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passgradeunreachable extends nopassgrade {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'passgradeunreachable';
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB;
        $courseid = (int) $context->course->id;
        $findings = [];
        // Quiz marks of the course in one query.
        $quizmarks = $DB->get_records_menu('quiz', ['course' => $courseid], '', 'id, sumgrades');
        foreach ($this->get_tracked_cms($context) as $cmid => $cm) {
            $usesgrade = $cm->completiongradeitemnumber !== null;
            $passgrade = !empty($cm->completionpassgrade);
            if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC || (!$usesgrade && !$passgrade)) {
                continue;
            }
            if ($cm->modname === 'quiz' && (float) ($quizmarks[$cm->instance] ?? 0) <= 0) {
                $findings[] = $this->make_finding(
                    $courseid,
                    'check:passgradeunreachable:messagequizempty',
                    $this->cm_params($cm),
                    ['quizempty' => 1],
                    (int) $cmid,
                    ['case' => 'quizempty']
                );
                continue;
            }
            $item = $this->get_completion_grade_item($context, $cm);
            if (!$item || (float) $item->gradepass <= (float) $item->grademax + 0.00001) {
                continue;
            }
            // With "Require grade" only and a hidden grade item, the grade to pass is ignored.
            if (!$passgrade && !empty($item->hidden)) {
                continue;
            }
            $findings[] = $this->make_finding(
                $courseid,
                'check:passgradeunreachable:messageactivity',
                $this->cm_params($cm) + ['gradepass' => format_float((float) $item->gradepass, 2),
                    'grademax' => format_float((float) $item->grademax, 2)],
                ['gradeitemid' => (int) $item->id, 'grademax' => (float) $item->grademax],
                (int) $cmid
            );
        }
        foreach ($context->get_criteria(COMPLETION_CRITERIA_TYPE_GRADE) as $criterion) {
            $courseitem = \grade_item::fetch_course_item($courseid);
            if ((float) $criterion->gradepass > (float) $courseitem->grademax + 0.00001) {
                $findings[] = $this->make_finding(
                    $courseid,
                    'check:passgradeunreachable:messagecourse',
                    ['gradepass' => format_float((float) $criterion->gradepass, 2),
                        'grademax' => format_float((float) $courseitem->grademax, 2)],
                    ['criterionid' => (int) $criterion->id, 'grademax' => (float) $courseitem->grademax],
                    null,
                    ['criterionid' => (int) $criterion->id]
                );
            }
        }
        return $findings;
    }

    /**
     * An empty quiz needs questions, not a setting.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return empty($finding->details['quizempty']);
    }

    /**
     * The new grade to pass, 80% of the maximum grade by default.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_fix_parameters(finding $finding): array {
        $parameters = parent::get_fix_parameters($finding);
        $parameters[0]['default'] = round((float) ($finding->details['grademax'] ?? 100) * 0.8, 2);
        return $parameters;
    }
}
