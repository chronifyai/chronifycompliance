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
use local_completiondoctor\fix\fixer;
use local_completiondoctor\local\access;
use local_completiondoctor\local\finding_store;

/**
 * Apply the fix of a finding.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_fix extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'findingid' => new external_value(PARAM_INT, 'Finding id'),
            'params' => preview_fix::params_structure(),
        ]);
    }

    /**
     * Apply the fix.
     *
     * @param int $findingid
     * @param array $params
     * @return array
     */
    public static function execute(int $findingid, array $params = []): array {
        global $USER;
        ['findingid' => $findingid, 'params' => $params] = self::validate_parameters(
            self::execute_parameters(),
            ['findingid' => $findingid, 'params' => $params]
        );
        $finding = finding_store::get($findingid);
        self::validate_context(access::get_finding_context($finding));
        access::require_finding_capability($finding, 'applyfix');

        $result = fixer::apply($findingid, preview_fix::clean_params($finding, $params), (int) $USER->id);
        return ['success' => $result->success, 'fixlogid' => (int) $result->fixlogid, 'message' => $result->message];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the change was made'),
            'fixlogid' => new external_value(PARAM_INT, 'Fix log id, for undo'),
            'message' => new external_value(PARAM_TEXT, 'Outcome'),
        ]);
    }
}
