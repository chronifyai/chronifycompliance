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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_chronifycompliance\fix\bulk_fixer;

/**
 * Apply the one click fixes of many courses that were shown in the combined preview.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_bulk_fix extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Course id')),
            'findingids' => new external_multiple_structure(new external_value(PARAM_INT, 'Finding id from the preview')),
        ]);
    }

    /**
     * Apply the fixes.
     *
     * @param int[] $courseids
     * @param int[] $findingids
     * @return array
     */
    public static function execute(array $courseids, array $findingids): array {
        global $USER;
        ['courseids' => $courseids, 'findingids' => $findingids] = self::validate_parameters(
            self::execute_parameters(),
            ['courseids' => $courseids, 'findingids' => $findingids]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewsitereport', $context);
        $result = bulk_fixer::apply($courseids, $findingids, (int) $USER->id);
        $component = 'local_chronifycompliance';
        $message = get_string('bulk:done', $component, ['fixed' => $result['fixed'], 'courses' => $result['courses']]);
        if ($result['failed']) {
            $message .= ' ' . get_string('bulk:failed', $component, $result['failed']);
        }
        return $result + ['message' => $message];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fixed' => new external_value(PARAM_INT, 'Fixes applied'),
            'failed' => new external_value(PARAM_INT, 'Fixes that could not be applied'),
            'courses' => new external_value(PARAM_INT, 'Courses changed'),
            'fixlogids' => new external_multiple_structure(new external_value(PARAM_INT, 'Fix log id, for undo')),
            'message' => new external_value(PARAM_TEXT, 'Outcome'),
        ]);
    }
}
