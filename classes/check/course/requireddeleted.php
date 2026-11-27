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
 * A completion criterion refers to an activity that no longer exists, belongs to another course, or
 * is being deleted. With "all activities" aggregation nobody can complete the course.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requireddeleted extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'requireddeleted';
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
        $required = $this->get_required_cms($context);
        if (!$required) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($required), SQL_PARAMS_NAMED);
        $cms = $DB->get_records_select('course_modules', "id {$insql}", $params, '', 'id, course, deletioninprogress');

        $findings = [];
        foreach ($required as $cmid => $criterion) {
            $cm = $cms[$cmid] ?? null;
            if ($cm && (int) $cm->course === (int) $context->course->id && !$cm->deletioninprogress) {
                continue;
            }
            $key = !$cm ? 'check:requireddeleted:message'
                : ($cm->deletioninprogress ? 'check:requireddeleted:messagedeleting' : 'check:requireddeleted:messageothercourse');
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                $key,
                ['cmid' => $cmid, 'modname' => $criterion->module],
                ['criterionid' => (int) $criterion->id],
                null,
                ['criterionid' => (int) $criterion->id]
            );
        }
        return $findings;
    }

    /**
     * Fixable when the finding names the criterion.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['criterionid']);
    }

    /**
     * Preview removing the criterion.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        global $DB;
        $criterion = $DB->get_record('course_completion_criteria', ['id' => $finding->details['criterionid']], '*', MUST_EXIST);
        $row = ['course' => (int) $criterion->course, 'criteriatype' => (int) $criterion->criteriatype,
            'module' => $criterion->module, 'moduleinstance' => (int) $criterion->moduleinstance];
        $preview = new fix_preview('removecriterion', ['criterion' => $row], ['criterion' => null]);
        $preview->add_row(
            get_string('fix:criterion', 'local_completiondoctor', $finding->messageparams),
            get_string('fix:required', 'local_completiondoctor'),
            get_string('fix:removed', 'local_completiondoctor')
        );
        $others = $DB->count_records('course_completion_criteria', ['course' => $finding->courseid]) - 1;
        if ($others === 0) {
            $preview->add_warning(get_string('fix:warning:lastcriterion', 'local_completiondoctor'));
        } else {
            $ready = criteria_editor::count_ready_without($finding->courseid, (int) $criterion->id, $others);
            $preview->add_warning(get_string('fix:impact:ready', 'local_completiondoctor', $ready));
        }
        return $preview;
    }

    /**
     * Remove the criterion.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $row = $preview->before['criterion'];
        if (!criteria_editor::remove_activity_criterion((int) $row['course'], (int) $row['moduleinstance'])) {
            throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
        }
        return new fix_result(true, $preview->after);
    }

    /**
     * Restore the criterion (as a new criterion row with the same settings).
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        if (!criteria_editor::restore_activity_criterion($before['criterion'])) {
            throw new \moodle_exception('error:undochanged', 'local_completiondoctor');
        }
        return new fix_result(true, $before);
    }
}
