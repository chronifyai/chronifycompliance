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
 * Preview the one click fixes of many courses (Completion workspace bulk fix). Writes nothing.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_bulk_fix extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Course id')),
        ]);
    }

    /**
     * Build the combined preview.
     *
     * @param int[] $courseids
     * @return array
     */
    public static function execute(array $courseids): array {
        ['courseids' => $courseids] = self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewsitereport', $context);
        $preview = bulk_fixer::preview($courseids);
        $preview['count'] = count($preview['items']);
        $preview['canapply'] = (bool) array_filter($preview['items'], fn($item) => $item['error'] === '');
        return $preview;
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(new external_single_structure([
                'findingid' => new external_value(PARAM_INT, 'Finding id'),
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'checkname' => new external_value(PARAM_TEXT, 'Check name'),
                'message' => new external_value(PARAM_TEXT, 'Problem'),
                'rows' => new external_multiple_structure(new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'What changes'),
                    'before' => new external_value(PARAM_TEXT, 'Value now'),
                    'after' => new external_value(PARAM_TEXT, 'Value after the fix'),
                ])),
                'warnings' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Warning')),
                'error' => new external_value(PARAM_TEXT, 'Why this fix cannot be applied, or empty'),
            ])),
            'count' => new external_value(PARAM_INT, 'Fixes in the preview'),
            'courses' => new external_value(PARAM_INT, 'Courses with fixes'),
            'needsinput' => new external_value(PARAM_INT, 'Fixable problems that need a decision, left out'),
            'over' => new external_value(PARAM_INT, 'One click problems over the limit, left for the next run'),
            'canapply' => new external_value(PARAM_BOOL, 'Whether there is anything to apply'),
        ]);
    }
}
