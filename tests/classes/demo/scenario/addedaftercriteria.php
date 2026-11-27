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

namespace local_completiondoctor\tests\demo\scenario;

use local_completiondoctor\tests\demo\scenario;

/**
 * Demo course for the addedaftercriteria check: a quiz was added an hour after the completion
 * settings were saved (recorded by the plugin's observer, and in the standard log when enabled).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addedaftercriteria extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'addedaftercriteria';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['addedaftercriteria'];
    }

    /**
     * Create the course.
     *
     * @param \testing_data_generator $generator
     * @param int $categoryid
     * @return \stdClass
     */
    public function create(\testing_data_generator $generator, int $categoryid): \stdClass {
        global $DB;
        $course = $generator->create_course($this->course_defaults($categoryid, $this->get_fullname()));
        $assign = $this->create_graded_assign($generator, (int) $course->id);
        $this->require_activities((int) $course->id, [(int) $assign->cmid]);

        // Record the save of the completion settings as core does, then date it an hour back.
        \core\event\course_completion_updated::create([
            'courseid' => $course->id,
            'context' => \context_course::instance($course->id),
        ])->trigger();
        \local_completiondoctor\local\audit_store::set_criteria_saved((int) $course->id, time() - HOURSECS);
        $readers = get_log_manager()->get_readers('\core\log\sql_internal_table_reader');
        if (isset($readers['logstore_standard'])) {
            $readers['logstore_standard']->flush();
            $table = $readers['logstore_standard']->get_internal_log_table_name();
            $DB->execute(
                "UPDATE {{$table}} SET timecreated = timecreated - 3600
                           WHERE courseid = :courseid AND eventname = :eventname",
                ['courseid' => $course->id, 'eventname' => '\core\event\course_completion_updated']
            );
        }

        $generator->create_module('quiz', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);
        return $course;
    }
}
