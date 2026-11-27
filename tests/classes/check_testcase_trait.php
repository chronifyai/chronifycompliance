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

namespace local_chronifycompliance\tests;

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\hook\register_checks;

/**
 * Helpers to register test checks through the real hook.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait check_testcase_trait {
    /**
     * Register checks via the register_checks hook and reset the registry.
     *
     * @param \local_chronifycompliance\check\base_check[] $checks
     */
    protected function register_checks(array $checks): void {
        $manager = \core\hook\manager::phpunit_get_instance([]);
        $manager->phpunit_redirect_hook(register_checks::class, function (register_checks $hook) use ($checks): void {
            foreach ($checks as $check) {
                $hook->add_check($check);
            }
        });
        \core\di::set(\core\hook\manager::class, $manager);
        check_registry::reset();
        fake_check::$runner = null;
    }

    /**
     * Reset the registry after a test.
     */
    protected function reset_checks(): void {
        check_registry::reset();
        fake_check::$runner = null;
    }
}
