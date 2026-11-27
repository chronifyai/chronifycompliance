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
 * Chronify.Compliance site dashboard.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\system_report_factory;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\reportbuilder\local\systemreports\dashboard;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_chronifycompliance_dashboard');
$context = context_system::instance();
require_capability('local/chronifycompliance:viewsitereport', $context);
$url = new moodle_url('/local/chronifycompliance/index.php');

if ($action === 'cancel') {
    require_sesskey();
    require_capability('local/chronifycompliance:runscan', $context);
    $active = scan_manager::get_active_scan();
    if ($active) {
        scan_manager::cancel((int) $active->id);
    }
    redirect($url, get_string('dashboard:scancancelled', 'local_chronifycompliance'), null, \core\output\notification::NOTIFY_INFO);
}

if ($action === 'scan') {
    require_sesskey();
    require_capability('local/chronifycompliance:runscan', $context);
    if (scan_manager::get_active_scan()) {
        redirect(
            $url,
            get_string('dashboard:scanrunning', 'local_chronifycompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    site_auditor::audit(true);
    $scanid = scan_manager::start_scan((int) $USER->id);
    $scan = scan_manager::get_scan($scanid);
    redirect(
        $url,
        get_string('dashboard:scanqueued', 'local_chronifycompliance', $scan->totalitems),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$view = optional_param('view', '', PARAM_ALPHA);
$checkid = optional_param('checkid', '', PARAM_ALPHANUMEXT);
if (!in_array($view, ['', 'critical', 'warning', 'info', 'completionoff', 'waiting', 'healthy'], true)) {
    $view = '';
}

\local_chronifycompliance\event\dashboard_viewed::create(['context' => $context])->trigger();
$renderer = $PAGE->get_renderer('local_chronifycompliance');
$PAGE->requires->js_call_amd('local_chronifycompliance/scan_progress', 'init', ['[data-region="scan-progress"]']);

echo $OUTPUT->header();
echo $renderer->dashboard_header($view, $checkid);

$report = system_report_factory::create(dashboard::class, $context, '', '', 0, ['view' => $view, 'checkid' => $checkid]);
echo $report->output();
echo $OUTPUT->footer();
