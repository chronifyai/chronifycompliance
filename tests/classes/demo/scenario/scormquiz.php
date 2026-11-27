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
 * Demo course for the scormquiz check: a SCORM quiz with an 80% pass mark that completes without the quiz being passed.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormquiz extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormquiz';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['scormquiz'];
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
        // A Storyline quiz (pass mark 80%) that reports "completed": only "Completed" is required, no score.
        $scorm = $this->create_scorm($generator, (int) $course->id, 'storyline_fake', ['completionstatusrequired' => 4]);
        $this->require_activities((int) $course->id, [(int) $scorm->cmid]);
        return $course;
    }
}
