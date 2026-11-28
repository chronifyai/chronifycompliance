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
 * A plugin waiting for its upgrade fails (the stored version of this plugin is lowered, for this test site
 * only); the real version passes.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgradeready extends site_scenario {
    /** @var int|null Real version of this plugin, restored by pass(). */
    private static ?int $version = null;

    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $CFG, $DB;
        self::$version = self::$version ?? (int) get_config('local_chronifycompliance', 'version');
        set_config('version', self::$version - 1, 'local_chronifycompliance');
        \core_plugin_manager::reset_caches();
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $CFG, $DB;
        if (self::$version !== null) {
            set_config('version', self::$version, 'local_chronifycompliance');
        }
        \core_plugin_manager::reset_caches();
    }
}
