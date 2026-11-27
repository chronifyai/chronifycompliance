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
 * Analyze SCORM packages from the command line.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\scorm\inspector;
use local_chronifycompliance\scorm\package_analyzer;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'file' => '',
    'cmid' => 0,
    'all' => false,
    'queue' => false,
    'refresh' => false,
    'json' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognized)));
}

$help = <<<EOT
Analyze SCORM packages: version, SCOs, authoring tool, reporting mode, size and connection speed.

Options:
  --file=PATH    Analyze a zip file (nothing is stored).
  --cmid=ID      Analyze a SCORM activity: package, what learners' attempts reported, recommendation.
  --all          Analyze every distinct package on the site (results are cached by content hash).
  --queue        With --all: add the packages to the analysis queue (processed a few per cron run).
  --refresh      Ignore cached results.
  --json         Print JSON.
  -h, --help     Print this help.

EOT;

if ($options['help'] || (!$options['file'] && !$options['cmid'] && !$options['all'])) {
    echo $help;
    exit($options['help'] ? 0 : 1);
}

\core\session\manager::set_user(get_admin());

if ($options['file']) {
    if (!is_readable($options['file'])) {
        cli_error('File not found: ' . $options['file']);
    }
    output(package_analyzer::analyze_path($options['file']), (bool) $options['json']);
    exit(0);
}

if ($options['cmid']) {
    $cm = get_fast_modinfo(get_coursemodule_from_id('scorm', (int) $options['cmid'], 0, false, MUST_EXIST)->course)
        ->get_cm((int) $options['cmid']);
    $inspection = inspector::inspect($cm, $options['refresh'] ? inspector::MODE_REFRESH : inspector::MODE_NOW);
    $inspection['scorm'] = array_intersect_key((array) $inspection['scorm'], array_flip(['id', 'version', 'scormtype',
        'completionstatusrequired', 'completionscorerequired', 'completionstatusallscos', 'masteryoverride', 'forcecompleted',
        'forcenewattempt', 'grademethod', 'whatgrade', 'maxattempt', 'popup', 'width', 'height', 'hidetoc', 'nav']));
    output($inspection, (bool) $options['json']);
    exit(0);
}

$hashes = package_analyzer::get_all_hashes();
if ($options['queue']) {
    foreach ($hashes as $hash) {
        package_analyzer::queue($hash);
    }
    cli_writeln(count($hashes) . ' packages are in the analysis queue (' . package_analyzer::count_queued() . ' waiting).');
    exit(0);
}

$start = microtime(true);
$statuses = [];
foreach ($hashes as $hash) {
    $file = package_analyzer::get_file_by_hash($hash);
    if (!$file) {
        continue;
    }
    $t = microtime(true);
    $analysis = package_analyzer::analyze_stored_file($file, (bool) $options['refresh']);
    $statuses[$analysis['status']] = ($statuses[$analysis['status']] ?? 0) + 1;
    cli_writeln(sprintf(
        '%s %-11s %-10s %-20s %6.2f Mbps %6.2f s',
        substr($hash, 0, 8),
        $analysis['status'],
        $analysis['tool']['tool'] ?? '-',
        $analysis['tool']['reportingmode'] ?? '-',
        $analysis['bandwidth']['minmbps'] ?? 0,
        microtime(true) - $t
    ));
}
fwrite(STDERR, sprintf(
    "Packages: %d, %s. Time %.1f s, peak memory %.1f MB\n",
    count($hashes),
    json_encode($statuses),
    microtime(true) - $start,
    memory_get_peak_usage() / 1048576
));

/**
 * Print an analysis.
 *
 * @param array $data
 * @param bool $json
 */
function output(array $data, bool $json): void {
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        return;
    }
    print_tree($data, 0);
}

/**
 * Print nested arrays as an indented list.
 *
 * @param array $data
 * @param int $depth
 */
function print_tree(array $data, int $depth): void {
    foreach ($data as $key => $value) {
        $indent = str_repeat('  ', $depth);
        if (is_array($value) || is_object($value)) {
            $value = (array) $value;
            if (!$value) {
                cli_writeln("{$indent}{$key}: -");
                continue;
            }
            cli_writeln("{$indent}{$key}:");
            print_tree($value, $depth + 1);
        } else {
            cli_writeln("{$indent}{$key}: " . (is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '-')));
        }
    }
}
