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

namespace local_chronifycompliance\tests\demo;

/**
 * A demo site state for one site check: fail() sets the site up so the check reports a finding, pass() so it
 * reports none. Used by the PHPUnit test of every site scenario and by Behat. Only for test sites.
 *
 * Site scenarios live in tests/classes/demo/site_scenario (namespace tests\demo\site_scenario), named after the
 * check they belong to, and are discovered automatically.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class site_scenario {
    /**
     * Id of the check, which is also the class name.
     *
     * @return string
     */
    public function get_id(): string {
        return substr(strrchr(static::class, '\\'), 1);
    }

    /**
     * Set the site up so the check reports a finding.
     *
     * @param \testing_data_generator $generator
     */
    abstract public function fail(\testing_data_generator $generator): void;

    /**
     * Set the site up so the check reports nothing.
     *
     * @param \testing_data_generator $generator
     */
    abstract public function pass(\testing_data_generator $generator): void;

    /**
     * All site scenarios.
     *
     * @return site_scenario[] Keyed by check id.
     */
    public static function get_all(): array {
        $scenarios = [];
        foreach (glob(__DIR__ . '/site_scenario/*.php') as $file) {
            $classname = __NAMESPACE__ . '\\site_scenario\\' . basename($file, '.php');
            if (is_subclass_of($classname, self::class)) {
                $scenario = new $classname();
                $scenarios[$scenario->get_id()] = $scenario;
            }
        }
        ksort($scenarios);
        return $scenarios;
    }
}
