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
 * Demo course for the scormgradenoscore check: a slides-only SCORM package that must receive a grade of at least 50.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormgradenoscore extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormgradenoscore';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        // The grade to pass also makes overlapgrade explain "complete (failed)".
        return ['overlapgrade', 'scormgradenoscore'];
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
        $scorm = $this->create_scorm(
            $generator,
            (int) $course->id,
            'storyline_noquiz',
            ['completionstatusrequired' => null, 'completionusegrade' => 1, 'grademethod' => 1]
        );
        $DB->set_field('grade_items', 'gradepass', 50, ['itemtype' => 'mod', 'itemmodule' => 'scorm',
            'iteminstance' => $scorm->id]);
        $this->require_activities((int) $course->id, [(int) $scorm->cmid]);
        return $course;
    }
}
