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
 * Development tool: time the main dashboard on this site, optionally with synthetic findings.
 *
 * --findings=N adds N open findings spread over the courses (marked with messagekey "measure") and
 * as many audit rows, measures, then deletes them again. Not part of the plugin release.
 *
 * Usage, from the Moodle directory:
 *   php local/chronifycompliance/dev/measure-dashboard.php --findings=30000
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\output\main_dashboard;

[$options] = cli_get_params(['findings' => 0, 'runs' => 5]);
$findings = (int) $options['findings'];
\core\session\manager::set_user(get_admin());
$PAGE->set_url('/local/chronifycompliance/index.php');
$PAGE->set_context(context_system::instance());
$output = $PAGE->get_renderer('core');

$courses = $DB->get_fieldset_select('course', 'id', 'id <> :site', ['site' => SITEID]);
$added = [];
if ($findings && $courses) {
    $checks = ['nopassgrade', 'nocriteria', 'weakcondition', 'manualrole', 'circular', 'scormnostatus'];
    $severities = [base_check::SEVERITY_CRITICAL, base_check::SEVERITY_WARNING, base_check::SEVERITY_INFO];
    $rows = [];
    for ($i = 0; $i < $findings; $i++) {
        $rows[] = ['courseid' => $courses[$i % count($courses)], 'checkid' => $checks[$i % count($checks)],
            'component' => 'local_chronifycompliance', 'severity' => $severities[$i % 3],
            'baseseverity' => $severities[$i % 3], 'fingerprint' => sha1('measure' . $i), 'status' => 'open',
            'messagekey' => 'measure', 'timecreated' => time(), 'timemodified' => time()];
        if (count($rows) === 1000) {
            $DB->insert_records('local_chronifycompliance_finding', $rows);
            $rows = [];
        }
    }
    $DB->insert_records('local_chronifycompliance_finding', $rows);
    $audits = array_map(fn($id) => ['courseid' => $id, 'timeaudited' => time(), 'timecreated' => time(),
        'timemodified' => time()], $courses);
    $DB->insert_records('local_chronifycompliance_audit', $audits);
    cli_writeln(count($courses) . " courses, {$findings} synthetic findings added.");
}

try {
    for ($run = 1; $run <= (int) $options['runs']; $run++) {
        $start = microtime(true);
        $queries = $DB->perf_get_reads();
        $html = $output->render(new main_dashboard());
        $ms = round((microtime(true) - $start) * 1000);
        cli_writeln("Run {$run}: {$ms} ms, " . ($DB->perf_get_reads() - $queries) . ' queries, ' . strlen($html) . ' bytes');
    }
} finally {
    if ($findings) {
        $DB->delete_records('local_chronifycompliance_finding', ['messagekey' => 'measure']);
        $DB->delete_records('local_chronifycompliance_audit');
        cli_writeln('Synthetic findings removed.');
    }
}
