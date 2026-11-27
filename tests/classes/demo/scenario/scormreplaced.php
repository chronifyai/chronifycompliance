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
 * Demo course for the scormreplaced check: the package was replaced after five learners saved their
 * position in the earlier version.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormreplaced extends scenario {
    /**
     * Scenario id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormreplaced';
    }

    /**
     * Checks expected to report findings.
     *
     * @return string[]
     */
    public function get_expected_checks(): array {
        return ['scormreplaced'];
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
        $course = $generator->create_course(['enablecompletion' => 0]
            + $this->course_defaults($categoryid, $this->get_fullname()));
        $scorm = $this->create_scorm($generator, (int) $course->id, 'scorm12_single', ['completion' => COMPLETION_TRACKING_NONE]);
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        $elementid = $DB->get_field('scorm_element', 'id', ['element' => 'cmi.core.lesson_location'])
            ?: $DB->insert_record('scorm_element', ['element' => 'cmi.core.lesson_location']);
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_and_enrol($course, 'student');
            $attemptid = $DB->insert_record('scorm_attempt', ['userid' => $user->id, 'scormid' => $scorm->id, 'attempt' => 1]);
            $DB->insert_record('scorm_scoes_value', ['scoid' => $scoid, 'attemptid' => $attemptid, 'elementid' => $elementid,
                'value' => 'slide-7', 'timemodified' => time() - 10 * DAYSECS]);
        }
        // Simulate a later package replacement: new revision, file stored now.
        $DB->set_field('scorm', 'revision', 2, ['id' => $scorm->id]);
        $context = \context_module::instance($scorm->cmid);
        $DB->set_field('files', 'timemodified', time(), ['contextid' => $context->id, 'component' => 'mod_scorm',
            'filearea' => 'package']);
        return $course;
    }
}
