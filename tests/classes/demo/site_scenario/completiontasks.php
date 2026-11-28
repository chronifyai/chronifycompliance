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
 * The regular completion task disabled fails; both completion tasks enabled and run a minute ago pass.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completiontasks extends site_scenario {
    /**
     * Fail state.
     *
     * @param \testing_data_generator $generator
     */
    public function fail(\testing_data_generator $generator): void {
        global $CFG, $DB;
        set_config('enablecompletion', 1);
        $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => '\\core\\task\\completion_regular_task']);
    }

    /**
     * Pass state.
     *
     * @param \testing_data_generator $generator
     */
    public function pass(\testing_data_generator $generator): void {
        global $CFG, $DB;
        set_config('enablecompletion', 1);
        foreach (['\\core\\task\\completion_regular_task', '\\core\\task\\completion_daily_task'] as $classname) {
            $DB->set_field('task_scheduled', 'disabled', 0, ['classname' => $classname]);
            $DB->set_field('task_scheduled', 'faildelay', 0, ['classname' => $classname]);
            $DB->set_field('task_scheduled', 'lastruntime', time() - MINSECS, ['classname' => $classname]);
        }
    }
}
