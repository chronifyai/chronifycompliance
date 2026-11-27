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

use local_chronifycompliance\availability\dependency_graph;
use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_check;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\activity_completion;
use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;

/**
 * "Receive a grade" is used without "receive a passing grade" although the activity has a pass grade.
 *
 * When the grade item is hidden, core ignores the pass grade and any grade completes the activity, so
 * failing learners complete (warning). When it is visible, failing learners get "complete, fail",
 * which does not count for course completion or "must be complete" restrictions; that is correct but
 * often not what the teacher expects (info).
 * Only activities that count (course criteria or completion restrictions) are reported.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overlapgrade extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'overlapgrade';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $required = $this->get_required_cms($context);
        $referenced = [];
        foreach (dependency_graph::build($context) as $refs) {
            foreach ($refs as $ref) {
                $referenced[$ref] = true;
            }
        }

        $findings = [];
        foreach ($this->get_tracked_cms($context) as $cmid => $cm) {
            if (
                $cm->completion != COMPLETION_TRACKING_AUTOMATIC || $cm->completiongradeitemnumber === null
                    || !empty($cm->completionpassgrade)
            ) {
                continue;
            }
            if (!isset($required[$cmid]) && !isset($referenced[$cmid])) {
                continue;
            }
            $item = $this->get_completion_grade_item($context, $cm);
            if (!$item || (float) $item->gradepass <= 0) {
                continue;
            }
            $params = $this->cm_params($cm) + ['gradepass' => format_float((float) $item->gradepass, 2)];
            if (!empty($item->hidden)) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:overlapgrade:messagehidden',
                    $params,
                    [],
                    (int) $cmid
                );
            } else {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:overlapgrade:messagevisible',
                    $params,
                    [],
                    (int) $cmid,
                    [],
                    self::SEVERITY_INFO
                );
            }
        }
        return $findings;
    }

    /**
     * Fixable for activities.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return $finding->cmid !== null;
    }

    /**
     * Preview requiring a passing grade.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        global $DB;
        $current = (int) $DB->get_field('course_modules', 'completionpassgrade', ['id' => $finding->cmid], MUST_EXIST);
        $preview = new fix_preview(
            'completionpassgrade',
            ['cmid' => $finding->cmid, 'completionpassgrade' => $current],
            ['cmid' => $finding->cmid, 'completionpassgrade' => 1]
        );
        $preview->add_row(get_string('completionpassgrade', 'completion'), get_string('no'), get_string('yes'));
        $preview->add_warning(get_string('fix:warning:norecalculation', 'local_chronifycompliance'));
        return $preview;
    }

    /**
     * Require a passing grade.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        activity_completion::update((int) $finding->cmid, ['completionpassgrade' => 1]);
        return new fix_result(true, $preview->after);
    }

    /**
     * Restore the previous setting.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        $current = activity_completion::current((int) $before['cmid'], ['completionpassgrade']);
        $this->assert_unchanged($current, ['completionpassgrade' => (int) $after['completionpassgrade']]);
        activity_completion::update((int) $before['cmid'], ['completionpassgrade' => (int) $before['completionpassgrade']]);
        return new fix_result(true, $before);
    }
}
