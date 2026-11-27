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
 * A correctly configured course: completion on, a graded activity with a pass grade, and an
 * activity criterion that requires it.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class healthy extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'healthy';
    }

    /**
     * No check should report anything.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return [];
    }

    /**
     * Create the course.
     *
     * @param \testing_data_generator $generator
     * @param int $categoryid
     * @return \stdClass
     */
    public function create(\testing_data_generator $generator, int $categoryid): \stdClass {
        $course = $generator->create_course($this->course_defaults(
            $categoryid,
            get_string('demo:course:healthy', 'local_completiondoctor'),
            get_string('demo:course:healthy:summary', 'local_completiondoctor')
        ));
        $assign = $this->create_graded_assign($generator, (int) $course->id);
        $this->require_activities((int) $course->id, [(int) $assign->cmid]);
        return $course;
    }
}
