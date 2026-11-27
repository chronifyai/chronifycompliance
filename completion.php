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
 * Completion workspace: every checked course with its health, filtered by group or problem.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\system_report_factory;
use local_chronifycompliance\local\scan_controls;
use local_chronifycompliance\reportbuilder\local\systemreports\dashboard;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_chronifycompliance_completion');
$context = context_system::instance();
require_capability('local/chronifycompliance:viewsitereport', $context);
$url = new moodle_url('/local/chronifycompliance/completion.php');

scan_controls::handle($action, $url);

$view = optional_param('view', '', PARAM_ALPHA);
$checkid = optional_param('checkid', '', PARAM_ALPHANUMEXT);
if (!in_array($view, ['', 'critical', 'warning', 'info', 'completionoff', 'waiting', 'healthy', 'scorm'], true)) {
    $view = '';
}

\local_chronifycompliance\event\dashboard_viewed::create(['context' => $context])->trigger();
$renderer = $PAGE->get_renderer('local_chronifycompliance');
$PAGE->requires->js_call_amd('local_chronifycompliance/scan_progress', 'init', ['[data-region="scan-progress"]']);
$PAGE->requires->js_call_amd('local_chronifycompliance/bulk_fix', 'init');

echo $OUTPUT->header();
echo $renderer->dashboard_header($view, $checkid);

$report = system_report_factory::create(dashboard::class, $context, '', '', 0, ['view' => $view, 'checkid' => $checkid]);
echo $report->output();
echo $OUTPUT->render(new \local_chronifycompliance\output\dashboard_header($view, $checkid, true));
echo $OUTPUT->footer();
