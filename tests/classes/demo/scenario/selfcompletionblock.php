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
 * Demo course for the selfcompletionblock check: Self completion is required but the course has no Self completion block.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selfcompletionblock extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'selfcompletionblock';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['selfcompletionblock'];
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
        $this->add_criterion((int) $course->id, COMPLETION_CRITERIA_TYPE_SELF);
        return $course;
    }
}
