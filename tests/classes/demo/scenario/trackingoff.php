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
 * Demo course for the trackingoff check: Completion tracking is off in the course, but an activity has a completion condition.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trackingoff extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'trackingoff';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['trackingoff'];
    }

    /**
     * Create the course.
     *
     * @param \testing_data_generator $generator
     * @param int $categoryid
     * @return \stdClass
     */
    public function create(\testing_data_generator $generator, int $categoryid): \stdClass {
        $course = $generator->create_course(['enablecompletion' => 0] + $this->course_defaults($categoryid, $this->get_fullname()));
        global $DB;
        $page = $generator->create_module('page', ['course' => $course->id]);
        // The activity form keeps completion settings when course completion is switched off later.
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $page->cmid]);
        rebuild_course_cache((int) $course->id, true);
        return $course;
    }
}
