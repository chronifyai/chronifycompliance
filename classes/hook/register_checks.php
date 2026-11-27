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

namespace local_chronifycompliance\hook;

use local_chronifycompliance\check\base_check;

/**
 * Hook allowing other plugins to add Chronify.Compliance checks.
 *
 * Register a callback in your plugin's db/hooks.php and call add_check() for each check.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to register additional Chronify.Compliance checks.')]
#[\core\attribute\tags('completion')]
final class register_checks {
    /** @var base_check[] Checks added by plugins. */
    private array $checks = [];

    /**
     * Add a check.
     *
     * @param base_check $check
     */
    public function add_check(base_check $check): void {
        $this->checks[] = $check;
    }

    /**
     * Get the checks added by plugins.
     *
     * @return base_check[]
     */
    public function get_checks(): array {
        return $this->checks;
    }
}
