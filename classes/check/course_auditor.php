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

namespace local_completiondoctor\check;

/**
 * Runs the course and activity checks against one course.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_auditor extends auditor {
    /**
     * Audit a course.
     *
     * @param int $courseid
     * @param bool $save Store the findings (otherwise a dry run that writes nothing).
     * @param string[]|null $checkids Only run these checks.
     * @return audit_result
     */
    public static function audit(int $courseid, bool $save = true, ?array $checkids = null): audit_result {
        global $DB;
        if ($courseid === (int) SITEID) {
            throw new \coding_exception('Use site_auditor for the site');
        }
        $started = time();
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $checks = self::filter_checks(
            check_registry::get_checks([base_check::SCOPE_COURSE, base_check::SCOPE_ACTIVITY], true),
            $checkids
        );

        $lock = $save ? self::get_lock('course_' . $courseid) : null;
        try {
            $result = self::run_checks(new audit_context($course, $save), $checks, false);
            $result->timestarted = $started;
            $result->waiting = \local_completiondoctor\scorm\package_analyzer::count_waiting((int) $course->id);
            if ($save) {
                self::save_result($result);
            }
            return $result;
        } finally {
            $lock?->release();
        }
    }
}
