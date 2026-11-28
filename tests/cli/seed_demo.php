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
 * Create demo courses that trigger each check. Development tool for small test sites only.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\tests\demo\seeder;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../demo_autoload.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'only' => '',
    'purge' => false,
    'list' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognized)));
}

if ($options['help']) {
    echo <<<EOT
Create one demo course per check that triggers it, plus a healthy course.
Refuses to run on sites with more than 100 users.

Options:
  --only=a,b     Only these scenarios.
  --list         List the scenarios.
  --purge        Delete the demo category and its courses.
  -h, --help     Print this help.

EOT;
    exit(0);
}

if ($options['list']) {
    foreach (seeder::get_scenarios() as $id => $scenario) {
        cli_writeln(sprintf('%-22s expects: %s', $id, implode(', ', $scenario->get_expected_checks()) ?: '-'));
    }
    exit(0);
}

try {
    seeder::assert_test_site();
} catch (moodle_exception $e) {
    cli_error($e->getMessage());
}

\core\session\manager::set_user(get_admin());

if ($options['purge']) {
    cli_writeln('Deleted ' . seeder::purge() . ' demo courses.');
    exit(0);
}

$only = $options['only'] !== '' ? array_filter(array_map('trim', explode(',', $options['only']))) : null;
$created = seeder::seed($only);
foreach ($created as $id => $courseid) {
    cli_writeln("{$id}: course {$courseid}");
}
cli_writeln(count($created) . ' demo courses created.');
