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
 * A permanent token of the site administrator without expiry fails; no tokens passes.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wstokens extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $DB;
        $service = $DB->get_record('external_services', ['shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE], '*', MUST_EXIST);
        \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, (int) get_admin()->id, \context_system::instance());
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $DB;
        $DB->delete_records('external_tokens');
    }
}
