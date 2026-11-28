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

use local_chronifycompliance\security\vulnerability_list;
use local_chronifycompliance\tests\demo\site_scenario;

/**
 * An old 4.5 release fails; a release newer than every fix in the list, with a list from today, passes.
 * Changes $CFG->release for the running request only.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class corevulnerabilities extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $CFG;
        vulnerability_list::set(null);
        $CFG->release = '4.5.10 (Build: 20260101)';
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $CFG;
        vulnerability_list::set(null);
        $list = vulnerability_list::load();
        $newest = [0, 0, 0];
        foreach ($list['advisories'] as $advisory) {
            foreach ($advisory['fixed'] as $fixed) {
                $version = vulnerability_list::parse_version($fixed);
                $newest = vulnerability_list::compare($version, $newest) > 0 ? $version : $newest;
            }
        }
        $CFG->release = implode('.', $newest) . ' (Build: 20991231)';
        vulnerability_list::set(['generated' => date('Y-m-d')] + $list);
    }
}
