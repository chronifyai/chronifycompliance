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
 * Automatic guest login fails; guests off everywhere passes.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guestaccess extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        set_config('autologinguests', 1);
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $DB;
        set_config('autologinguests', 0);
        set_config('guestloginbutton', 0);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['enrol' => 'guest']);
    }
}
