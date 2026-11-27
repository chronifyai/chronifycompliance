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
 * Demo course for the passgradeunreachable check: an assignment out of 50 points that needs 80 to pass.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passgradeunreachable extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'passgradeunreachable';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['passgradeunreachable'];
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
        $assign = $this->create_graded_assign($generator, (int) $course->id, ['grade' => 50, 'gradepass' => 40]);
        // The maximum grade was lowered after the grade to pass was set: Moodle does not check it again.
        $DB->set_field('grade_items', 'gradepass', 80, ['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id]);
        $this->require_activities((int) $course->id, [(int) $assign->cmid]);
        return $course;
    }
}
