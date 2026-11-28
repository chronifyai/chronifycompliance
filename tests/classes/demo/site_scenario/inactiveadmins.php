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
 * An administrator who has not signed in for a year fails; recent sign ins pass.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inactiveadmins extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $DB;
        $DB->set_field('user', 'lastaccess', time() - YEARSECS, ['id' => get_admin()->id]);
        $DB->set_field('user', 'lastlogin', time() - YEARSECS, ['id' => get_admin()->id]);
        $DB->set_field('user', 'currentlogin', time() - YEARSECS, ['id' => get_admin()->id]);
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $DB;
        foreach (get_admins() as $admin) {
            $DB->set_field('user', 'lastaccess', time(), ['id' => $admin->id]);
        }
    }
}
