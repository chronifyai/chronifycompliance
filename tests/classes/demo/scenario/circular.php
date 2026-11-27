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
 * Demo course for the circular check: Two pages each require the other to be completed first.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circular extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'circular';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['circular'];
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
        $first = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $second = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $needs = fn(int $cmid) => json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => $cmid,
            'e' => COMPLETION_COMPLETE]], 'showc' => [true]]);
        $DB->set_field('course_modules', 'availability', $needs((int) $second->cmid), ['id' => $first->cmid]);
        $DB->set_field('course_modules', 'availability', $needs((int) $first->cmid), ['id' => $second->cmid]);
        rebuild_course_cache((int) $course->id, true);
        return $course;
    }
}
