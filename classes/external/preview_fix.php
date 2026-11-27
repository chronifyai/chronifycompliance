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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_completiondoctor\check\check_registry;
use local_completiondoctor\fix\fixer;
use local_completiondoctor\local\access;
use local_completiondoctor\local\finding_store;

/**
 * Preview the fix of a finding: what would change, and which parameters are needed.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_fix extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'findingid' => new external_value(PARAM_INT, 'Finding id'),
            'params' => self::params_structure(),
        ]);
    }

    /**
     * Structure of fix parameters (name/value pairs).
     *
     * @return external_multiple_structure
     */
    public static function params_structure(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'name' => new external_value(PARAM_ALPHANUMEXT, 'Parameter name'),
            'value' => new external_value(PARAM_RAW, 'Parameter value'),
        ]), 'Fix parameters', VALUE_DEFAULT, []);
    }

    /**
     * Convert name/value pairs to an array, cleaning each value with the type the check declares.
     *
     * @param \local_completiondoctor\check\finding $finding
     * @param array $pairs
     * @return array
     */
    public static function clean_params(\local_completiondoctor\check\finding $finding, array $pairs): array {
        $check = check_registry::get_check($finding->checkid);
        $types = [];
        foreach ($check ? $check->get_fix_parameters($finding) : [] as $definition) {
            $types[$definition['name']] = $definition['type'];
        }
        $params = [];
        foreach ($pairs as $pair) {
            if (isset($types[$pair['name']])) {
                $params[$pair['name']] = clean_param($pair['value'], $types[$pair['name']]);
            }
        }
        return $params;
    }

    /**
     * Preview the fix.
     *
     * @param int $findingid
     * @param array $params
     * @return array
     */
    public static function execute(int $findingid, array $params = []): array {
        ['findingid' => $findingid, 'params' => $params] = self::validate_parameters(
            self::execute_parameters(),
            ['findingid' => $findingid, 'params' => $params]
        );
        $finding = finding_store::get($findingid);
        $context = access::get_finding_context($finding);
        self::validate_context($context);
        access::require_finding_capability($finding, 'applyfix');

        $check = check_registry::get_check($finding->checkid);
        $definitions = $check ? $check->get_fix_parameters($finding) : [];
        $cleaned = self::clean_params($finding, $params);
        $result = ['findingid' => $findingid, 'rows' => [], 'warnings' => [], 'error' => '', 'canapply' => false,
            'parameters' => []];
        foreach ($definitions as $definition) {
            $result['parameters'][] = [
                'name' => $definition['name'],
                'label' => $definition['label'],
                'value' => (string) ($cleaned[$definition['name']] ?? $definition['default'] ?? ''),
            ];
        }
        // Use defaults for the first preview so the user sees the change before editing values.
        foreach ($definitions as $definition) {
            if (!array_key_exists($definition['name'], $cleaned) && isset($definition['default'])) {
                $cleaned[$definition['name']] = $definition['default'];
            }
        }
        try {
            $preview = fixer::preview($findingid, $cleaned);
            $result['rows'] = $preview->rows;
            $result['warnings'] = $preview->warnings;
            $result['canapply'] = $finding->is_open();
        } catch (\invalid_parameter_exception $e) {
            $result['error'] = $e->debuginfo ?: $e->getMessage();
        }
        return $result;
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'findingid' => new external_value(PARAM_INT, 'Finding id'),
            'rows' => new external_multiple_structure(new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'What changes'),
                'before' => new external_value(PARAM_TEXT, 'Value before'),
                'after' => new external_value(PARAM_TEXT, 'Value after'),
            ])),
            'warnings' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Warning')),
            'parameters' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'Parameter name'),
                'label' => new external_value(PARAM_TEXT, 'Label'),
                'value' => new external_value(PARAM_RAW, 'Current value'),
            ])),
            'error' => new external_value(PARAM_TEXT, 'Why the parameters are invalid, if they are'),
            'canapply' => new external_value(PARAM_BOOL, 'Whether the fix can be applied now'),
        ]);
    }
}
