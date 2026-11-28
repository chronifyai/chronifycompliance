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
use local_chronifycompliance\fix\fix_log;
use local_chronifycompliance\fix\fixer;

/**
 * Undo a fix from the fix log.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class undo_fix extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'fixlogid' => new external_value(PARAM_INT, 'Fix log id'),
        ]);
    }

    /**
     * Undo the fix.
     *
     * @param int $fixlogid
     * @return array
     */
    public static function execute(int $fixlogid): array {
        global $USER;
        ['fixlogid' => $fixlogid] = self::validate_parameters(self::execute_parameters(), ['fixlogid' => $fixlogid]);
        $log = fix_log::get($fixlogid);
        $context = (int) $log->courseid === (int) SITEID ? \context_system::instance() : \context_course::instance($log->courseid);
        self::validate_context($context);
        require_capability(
            $context->contextlevel == CONTEXT_SYSTEM ? 'moodle/site:config' : 'local/chronifycompliance:applyfix',
            $context
        );

        $result = fixer::undo($fixlogid, (int) $USER->id);
        return ['success' => $result->success, 'message' => $result->message];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the change was reverted'),
            'message' => new external_value(PARAM_TEXT, 'Outcome'),
        ]);
    }
}
