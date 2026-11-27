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

namespace local_chronifycompliance\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_chronifycompliance\scorm\inspector;

/**
 * Analyze the package of a SCORM activity: what it reports, recommended settings, speed and display.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_scorm extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of a SCORM activity'),
            'refresh' => new external_value(PARAM_BOOL, 'Analyze again even when cached', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Analyze.
     *
     * @param int $cmid
     * @param bool $refresh
     * @return array
     */
    public static function execute(int $cmid, bool $refresh = false): array {
        ['cmid' => $cmid, 'refresh' => $refresh] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'refresh' => $refresh]
        );
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'scorm');
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewcoursereport', $context);

        // Analyzing again runs in a background task; the stored analysis is returned.
        if ($refresh) {
            \local_chronifycompliance\task\check_course::request((int) $course->id, (int) $cm->id, true);
        }
        $inspection = inspector::inspect($cm, inspector::MODE_READ);
        return ['cmid' => $cmid] + scorm_exporter::export(
            $inspection['package'],
            $inspection['evidence'],
            $inspection['recommendation'],
            $inspection['stageissues']
        );
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $structure = scorm_exporter::structure();
        $structure->keys = ['cmid' => new external_value(PARAM_INT, 'Course module id')] + $structure->keys;
        return $structure;
    }
}
