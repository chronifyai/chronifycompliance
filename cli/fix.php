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
 * Preview, apply or undo a Completion Doctor fix from the command line.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_completiondoctor\fix\fixer;
use local_completiondoctor\local\finding_store;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'finding' => 0,
    'params' => '',
    'undo' => 0,
    'confirm' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognized)));
}

$help = <<<EOT
Preview, apply or undo a Completion Doctor fix.

Without --confirm only the preview is printed and nothing is changed.

Options:
  --finding=ID       Finding to fix.
  --params=JSON      Fix parameters, for example '{"gradepass":50}'.
  --undo=FIXLOGID    Undo a fix from the fix log.
  --confirm          Apply the fix (or the undo).
  -h, --help         Print this help.

EOT;

$findingid = (int) $options['finding'];
$fixlogid = (int) $options['undo'];
if ($options['help'] || (!$findingid && !$fixlogid)) {
    echo $help;
    exit($options['help'] ? 0 : 1);
}

$admin = get_admin();
\core\session\manager::set_user($admin);

if ($fixlogid) {
    if (!$options['confirm']) {
        cli_writeln("Add --confirm to undo fix {$fixlogid}.");
        exit(0);
    }
    $result = fixer::undo($fixlogid, (int) $admin->id);
    cli_writeln($result->success ? "Fix {$fixlogid} undone. {$result->message}" : "Undo failed. {$result->message}");
    exit($result->success ? 0 : 1);
}

$params = [];
if ($options['params'] !== '') {
    $params = json_decode($options['params'], true);
    if (!is_array($params)) {
        cli_error('--params must be a JSON object.');
    }
}

$finding = finding_store::get($findingid);
cli_writeln("Finding {$findingid} (course {$finding->courseid}, check {$finding->checkid}): " . $finding->get_message());
$preview = fixer::preview($findingid, $params);
foreach ($preview->rows as $row) {
    cli_writeln(sprintf('  %s: %s -> %s', $row['label'], $row['before'], $row['after']));
}
foreach ($preview->warnings as $warning) {
    cli_writeln('  Warning: ' . $warning);
}

if (!$options['confirm']) {
    cli_writeln('Preview only. Add --confirm to apply.');
    exit(0);
}
$result = fixer::apply($findingid, $params, (int) $admin->id);
if ($result->success) {
    cli_writeln("Applied. Fix log id {$result->fixlogid} (undo with --undo={$result->fixlogid} --confirm).");
    exit(0);
}
cli_writeln('Fix failed. ' . $result->message);
exit(1);
