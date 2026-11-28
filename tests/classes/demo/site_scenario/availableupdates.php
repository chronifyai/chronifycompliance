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
 * Update notifications off fails; on, checked a minute ago and nothing to update passes. The check reads Moodle's
 * stored response, so the pass state stores an empty one.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availableupdates extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        set_config('updateautocheck', 0);
        \core\update\checker::reset_caches(true);
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        set_config('disableupdatenotifications', 0);
        set_config('updateautocheck', 1);
        set_config('recentfetch', time() - MINSECS, 'core_plugin');
        set_config(
            'recentresponse',
            json_encode(['status' => 'OK', 'provider' => 'https://download.moodle.org',
            'apiver' => '1.3', 'timegenerated' => time(), 'forbranch' => moodle_major_version(true), 'updates' => []]),
            'core_plugin'
        );
        \core\update\checker::reset_caches(true);
        \core_plugin_manager::reset_caches();
    }
}
