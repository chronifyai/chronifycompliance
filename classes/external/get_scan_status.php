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

namespace local_completiondoctor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_completiondoctor\local\scan_manager;

/**
 * Progress of the latest site scan.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_scan_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Status of the latest scan.
     *
     * @return array
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/completiondoctor:viewsitereport', $context);
        $scan = scan_manager::get_latest_scan();
        return [
            'scanid' => $scan ? (int) $scan->id : 0,
            'status' => $scan ? $scan->status : '',
            'total' => $scan ? (int) $scan->totalitems : 0,
            'processed' => $scan ? (int) $scan->processed : 0,
            'failed' => $scan ? (int) $scan->failed : 0,
            'timestarted' => $scan ? (int) $scan->timestarted : 0,
            'timefinished' => $scan ? (int) $scan->timefinished : 0,
        ];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scanid' => new external_value(PARAM_INT, 'Scan id, 0 when no scan ran yet'),
            'status' => new external_value(PARAM_ALPHA, 'queued, running, finished or cancelled'),
            'total' => new external_value(PARAM_INT, 'Courses to audit'),
            'processed' => new external_value(PARAM_INT, 'Courses audited'),
            'failed' => new external_value(PARAM_INT, 'Courses that failed'),
            'timestarted' => new external_value(PARAM_INT, 'Start time'),
            'timefinished' => new external_value(PARAM_INT, 'End time, 0 while running'),
        ]);
    }
}
