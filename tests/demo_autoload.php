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

/**
 * Autoloader for the demo course generator in tests/classes/demo, for tests/cli/seed_demo.php and Behat.
 * PHPUnit loads these classes by itself; normal requests never load them.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

spl_autoload_register(function (string $classname): void {
    $prefix = 'local_chronifycompliance\\tests\\demo\\';
    if (!str_starts_with($classname, $prefix)) {
        return;
    }
    $file = __DIR__ . '/classes/demo/' . str_replace('\\', '/', substr($classname, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once($file);
    }
});
