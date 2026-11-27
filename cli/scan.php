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
 * Audit courses from the command line.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_completiondoctor\check\audit_result;
use local_completiondoctor\check\base_check;
use local_completiondoctor\check\check_registry;
use local_completiondoctor\check\course_auditor;
use local_completiondoctor\check\site_auditor;
use local_completiondoctor\local\scan_manager;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'course' => null,
    'category' => null,
    'all' => false,
    'site' => false,
    'limit' => 0,
    'format' => 'table',
    'severity' => 'info',
    'checks' => '',
    'save' => false,
    'queue' => false,
    'list' => false,
    'cancel' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognized)));
}

$help = <<<EOT
Audit course completion setup with Completion Doctor.

Without --save this is a dry run: nothing is written, findings are only printed.

Options:
  --course=ID        Audit one course.
  --category=ID      Audit all courses in a category and its subcategories.
  --all              Audit all courses.
  --site             Run the site checks.
  --limit=N          Audit at most N courses.
  --format=FORMAT    table (default), json or csv.
  --severity=LEVEL   Minimum severity to print: info (default), warning or critical.
  --checks=a,b       Only run these checks.
  --save             Store the findings (updates the course reports).
  --queue            Queue background tasks instead of auditing now (always stores the findings).
  --list             List the available checks.
  --cancel           Cancel the running site or category scan.
  -h, --help         Print this help.

Examples:
  php local/completiondoctor/cli/scan.php --course=12
  php local/completiondoctor/cli/scan.php --all --severity=critical --format=csv
  php local/completiondoctor/cli/scan.php --all --queue

EOT;

if ($options['help']) {
    echo $help;
    exit(0);
}

\core\session\manager::set_user(get_admin());

if ($options['cancel']) {
    $active = scan_manager::get_active_scan();
    if (!$active) {
        cli_writeln('No scan is running.');
        exit(0);
    }
    scan_manager::cancel((int) $active->id, false);
    cli_writeln("Scan {$active->id} cancelled.");
    exit(0);
}

if ($options['list']) {
    if (!check_registry::get_checks()) {
        cli_writeln(get_string('settings:nochecks', 'local_completiondoctor'));
    }
    foreach (check_registry::get_checks() as $id => $check) {
        printf(
            "%-22s %-9s %-9s %s%s\n",
            $id,
            $check->get_scope(),
            severity_name($check->get_severity()),
            $check->get_name(),
            $check->is_enabled() ? '' : ' (disabled)'
        );
    }
    exit(0);
}

$severities = ['info' => base_check::SEVERITY_INFO, 'warning' => base_check::SEVERITY_WARNING,
    'critical' => base_check::SEVERITY_CRITICAL];
if (!isset($severities[$options['severity']])) {
    cli_error('Invalid --severity. Use info, warning or critical.');
}
$minseverity = $severities[$options['severity']];
if (!in_array($options['format'], ['table', 'json', 'csv'], true)) {
    cli_error('Invalid --format. Use table, json or csv.');
}

$checkids = null;
if ($options['checks'] !== '') {
    $checkids = array_filter(array_map('trim', explode(',', $options['checks'])));
    foreach ($checkids as $checkid) {
        if (!check_registry::get_check($checkid)) {
            cli_error("Unknown check: {$checkid}. Use --list to see the available checks.");
        }
    }
}

$courseid = $options['course'] ? (int) $options['course'] : null;
$categoryid = $options['category'] ? (int) $options['category'] : null;
$limit = max(0, (int) $options['limit']);
$save = (bool) $options['save'];

if (!$courseid && !$categoryid && !$options['all'] && !$options['site']) {
    echo $help;
    exit(1);
}

if ($options['queue']) {
    if ($courseid) {
        scan_manager::queue_course($courseid);
        cli_writeln("Queued an audit of course {$courseid}.");
        exit(0);
    }
    $scanid = scan_manager::start_scan((int) get_admin()->id, $categoryid, $limit ?: null, $checkids);
    $scan = scan_manager::get_scan($scanid);
    cli_writeln("Scan {$scanid}: queued {$scan->totalitems} courses in batches of {$scan->batchsize}. "
        . 'Cron runs the tasks.');
    exit(0);
}

$results = [];
if ($options['site']) {
    $results[] = site_auditor::audit($save, $checkids);
}
if ($courseid) {
    $results[] = course_auditor::audit($courseid, $save, $checkids);
} else if ($categoryid || $options['all']) {
    [$sql, $params] = scan_manager::get_course_ids_sql($categoryid);
    $recordset = $DB->get_recordset_sql($sql, $params, 0, $limit);
    foreach ($recordset as $record) {
        try {
            $result = course_auditor::audit((int) $record->id, $save, $checkids);
        } catch (\Throwable $e) {
            cli_problem("Course {$record->id}: " . $e->getMessage());
            continue;
        }
        // Keep only what is printed, so memory stays flat on large sites.
        $result->findings = array_values(array_filter(
            $result->findings,
            fn($f) => $f->severity >= $minseverity
        ));
        $results[] = $result;
    }
    $recordset->close();
}

print_results($results, $minseverity, $options['format']);
print_summary($results, $save);

/**
 * Name of a severity.
 *
 * @param int $severity
 * @return string
 */
function severity_name(int $severity): string {
    return [base_check::SEVERITY_INFO => 'info', base_check::SEVERITY_WARNING => 'warning',
        base_check::SEVERITY_CRITICAL => 'critical'][$severity] ?? (string) $severity;
}

/**
 * Print the findings.
 *
 * @param audit_result[] $results
 * @param int $minseverity
 * @param string $format
 */
function print_results(array $results, int $minseverity, string $format): void {
    $rows = [];
    foreach ($results as $result) {
        foreach ($result->findings as $finding) {
            if ($finding->severity < $minseverity) {
                continue;
            }
            $rows[] = [
                'courseid' => $finding->courseid,
                'cmid' => $finding->cmid,
                'check' => $finding->checkid,
                'severity' => severity_name($finding->severity),
                'status' => $finding->status,
                'fixable' => $finding->fixable ? 'yes' : 'no',
                'message' => $finding->get_message(),
            ];
        }
    }

    if ($format === 'json') {
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        return;
    }
    if ($format === 'csv') {
        $out = fopen('php://stdout', 'w');
        fputcsv($out, ['courseid', 'cmid', 'check', 'severity', 'status', 'fixable', 'message'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        return;
    }
    if (!$rows) {
        cli_writeln('No findings.');
        return;
    }
    printf("%-8s %-8s %-20s %-9s %-7s %s\n", 'course', 'cmid', 'check', 'severity', 'fixable', 'message');
    foreach ($rows as $row) {
        printf(
            "%-8s %-8s %-20s %-9s %-7s %s\n",
            $row['courseid'],
            $row['cmid'] ?? '-',
            $row['check'],
            $row['severity'],
            $row['fixable'],
            core_text::substr($row['message'], 0, 120)
        );
    }
}

/**
 * Print run statistics to STDERR, so json/csv output stays clean.
 *
 * @param audit_result[] $results
 * @param bool $saved
 */
function print_summary(array $results, bool $saved): void {
    $courses = 0;
    $findings = 0;
    $errors = [];
    $time = 0.0;
    $queries = 0;
    $slowest = null;
    foreach ($results as $result) {
        $courses++;
        $findings += count($result->findings);
        $time += $result->duration;
        $queries += $result->queries;
        foreach ($result->errors as $checkid => $message) {
            $errors[] = "course {$result->courseid}, {$checkid}: {$message}";
        }
        if (!$slowest || $result->duration > $slowest->duration) {
            $slowest = $result;
        }
    }
    $lines = [
        sprintf(
            'Audited: %d, findings printed: %d, check errors: %d, %s',
            $courses,
            $findings,
            count($errors),
            $saved ? 'findings saved' : 'dry run (nothing saved)'
        ),
        sprintf(
            'Time: %.2f s, queries: %d (avg %.1f per audit), peak memory: %.1f MB',
            $time,
            $queries,
            $courses ? $queries / $courses : 0,
            memory_get_peak_usage() / 1048576
        ),
    ];
    if ($slowest) {
        $lines[] = sprintf(
            'Slowest: course %d, %.3f s, %d queries',
            $slowest->courseid,
            $slowest->duration,
            $slowest->queries
        );
    }
    foreach ($errors as $error) {
        $lines[] = 'Error: ' . $error;
    }
    fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL);
}
