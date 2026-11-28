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

namespace local_chronifycompliance\tests\demo\site_scenario;

use local_chronifycompliance\tests\demo\site_scenario;

/**
 * A log that is never cleaned with 20,000 events a day fails; logs kept for a year pass.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logretention extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $CFG, $DB;
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        get_log_manager(true);
        set_config('loglifetime', 0, 'logstore_standard');
        $rows = [];
        for ($day = 1; $day <= 30; $day++) {
            $rows[] = ['day' => (int) date('Ymd', time() - $day * DAYSECS), 'activeusers' => 100, 'events' => 20000, 'logins' => 50,
                'failedlogins' => 0, 'completions' => 0];
        }
        $DB->delete_records('local_chronifycompliance_siteday');
        $DB->insert_records('local_chronifycompliance_siteday', $rows);
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $CFG, $DB;
        set_config('loglifetime', 365, 'logstore_standard');
    }
}
