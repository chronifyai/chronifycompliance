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

namespace local_chronifycompliance\tests\demo\scenario;

use local_chronifycompliance\tests\demo\scenario;

/**
 * Demo course for the prereqbroken check: Completion requires another course that does not track completion.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prereqbroken extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'prereqbroken';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['prereqbroken'];
    }

    /**
     * Create the course.
     *
     * @param \testing_data_generator $generator
     * @param int $categoryid
     * @return \stdClass
     */
    public function create(\testing_data_generator $generator, int $categoryid): \stdClass {
        $course = $generator->create_course($this->course_defaults($categoryid, $this->get_fullname()));
        $prerequisite = $generator->create_course([
            'category' => $categoryid,
            'fullname' => get_string('demo:course:prerequisite', 'local_chronifycompliance'),
            'shortname' => 'cd-demo-prereqbroken-target',
            'enablecompletion' => 0,
        ]);
        $this->add_criterion((int) $course->id, COMPLETION_CRITERIA_TYPE_COURSE, ['courseinstance' => $prerequisite->id]);
        return $course;
    }
}
