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
use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;
use stdClass;

/**
 * Activities track completion but completion tracking is off in the course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trackingoff extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'trackingoff';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_INFO;
    }

    /**
     * Only courses with completion off, on sites with completion on (the site check covers the rest).
     *
     * @param stdClass $course
     * @return bool
     */
    public function applies_to_course(stdClass $course): bool {
        global $CFG;
        return !empty($CFG->enablecompletion) && empty($course->enablecompletion);
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB;
        $count = $DB->count_records_select(
            'course_modules',
            'course = :course AND completion > 0 AND deletioninprogress = 0',
            ['course' => $context->course->id]
        );
        if (!$count) {
            return [];
        }
        return [$this->make_finding((int) $context->course->id, 'check:trackingoff:message', ['count' => $count])];
    }

    /**
     * Always fixable.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return true;
    }

    /**
     * Preview enabling completion tracking.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $preview = new fix_preview('enablecompletion', ['enablecompletion' => 0], ['enablecompletion' => 1]);
        return $preview->add_row(get_string('enablecompletion', 'completion'), get_string('no'), get_string('yes'));
    }

    /**
     * Enable completion tracking.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $this->set_enablecompletion($finding->courseid, 1);
        return new fix_result(true, $preview->after);
    }

    /**
     * Disable completion tracking again.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, stdClass $fixlog): fix_result {
        global $DB;
        $current = (int) $DB->get_field('course', 'enablecompletion', ['id' => $fixlog->courseid], MUST_EXIST);
        $this->assert_unchanged(['enablecompletion' => $current], ['enablecompletion' => (int) $after['enablecompletion']]);
        $this->set_enablecompletion((int) $fixlog->courseid, (int) $before['enablecompletion']);
        return new fix_result(true, $before);
    }

    /**
     * Change the course setting through the course API.
     *
     * @param int $courseid
     * @param int $value
     */
    private function set_enablecompletion(int $courseid, int $value): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        update_course((object) ['id' => $courseid, 'enablecompletion' => $value]);
    }
}
