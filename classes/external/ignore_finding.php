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
use local_completiondoctor\check\finding;
use local_completiondoctor\local\access;
use local_completiondoctor\local\finding_store;

/**
 * Ignore a finding with a reason, or reopen an ignored one.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_finding extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'findingid' => new external_value(PARAM_INT, 'Finding id'),
            'reason' => new external_value(PARAM_TEXT, 'Why the finding is ignored', VALUE_DEFAULT, ''),
            'ignore' => new external_value(PARAM_BOOL, 'True to ignore, false to reopen', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Ignore or reopen.
     *
     * @param int $findingid
     * @param string $reason
     * @param bool $ignore
     * @return array
     */
    public static function execute(int $findingid, string $reason = '', bool $ignore = true): array {
        global $USER;
        ['findingid' => $findingid, 'reason' => $reason, 'ignore' => $ignore] = self::validate_parameters(
            self::execute_parameters(),
            ['findingid' => $findingid, 'reason' => $reason, 'ignore' => $ignore]
        );
        $finding = finding_store::get($findingid);
        self::validate_context(access::get_finding_context($finding));
        access::require_finding_capability($finding, 'ignorefinding');

        $event = null;
        if ($ignore) {
            $finding = finding_store::ignore($findingid, trim($reason), (int) $USER->id);
            $event = \local_completiondoctor\event\finding_ignored::class;
        } else if ($finding->status === finding::STATUS_IGNORED) {
            finding_store::set_status($findingid, finding::STATUS_OPEN);
            $finding = finding_store::get($findingid);
            $event = \local_completiondoctor\event\finding_reopened::class;
        }
        if ($event) {
            $event::create(['context' => access::get_finding_context($finding), 'objectid' => $findingid,
                'other' => ['checkid' => $finding->checkid]])->trigger();
        }
        return ['success' => true, 'status' => $finding->status];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Done'),
            'status' => new external_value(PARAM_ALPHA, 'New status'),
        ]);
    }
}
