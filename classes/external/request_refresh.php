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
use local_chronifycompliance\data\dataset_registry;
use local_chronifycompliance\data\refresher;

/**
 * Refresh now: queue a background refresh of one dataset.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_refresh extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'dataset' => new external_value(PARAM_ALPHANUM, 'Dataset id'),
        ]);
    }

    /**
     * Queue the refresh.
     *
     * @param string $dataset
     * @return array
     */
    public static function execute(string $dataset): array {
        ['dataset' => $dataset] = self::validate_parameters(self::execute_parameters(), ['dataset' => $dataset]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/chronifycompliance:runscan', $context);
        if (!dataset_registry::get_dataset($dataset)) {
            throw new \invalid_parameter_exception('Unknown dataset ' . $dataset);
        }
        refresher::request($dataset);
        return ['queued' => true, 'message' => get_string('dataage:queued', 'local_chronifycompliance')];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queued' => new external_value(PARAM_BOOL, 'Whether the refresh was queued'),
            'message' => new external_value(PARAM_TEXT, 'What happens next'),
        ]);
    }
}
