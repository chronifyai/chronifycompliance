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
 * Completion Doctor site dashboard.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\system_report_factory;
use local_completiondoctor\check\site_auditor;
use local_completiondoctor\local\scan_manager;
use local_completiondoctor\reportbuilder\local\systemreports\dashboard;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_completiondoctor_dashboard');
$context = context_system::instance();
require_capability('local/completiondoctor:viewsitereport', $context);
$url = new moodle_url('/local/completiondoctor/index.php');

if ($action === 'cancel') {
    require_sesskey();
    require_capability('local/completiondoctor:runscan', $context);
    $active = scan_manager::get_active_scan();
    if ($active) {
        scan_manager::cancel((int) $active->id);
    }
    redirect($url, get_string('dashboard:scancancelled', 'local_completiondoctor'), null, \core\output\notification::NOTIFY_INFO);
}

if ($action === 'scan') {
    require_sesskey();
    require_capability('local/completiondoctor:runscan', $context);
    if (scan_manager::get_active_scan()) {
        redirect(
            $url,
            get_string('dashboard:scanrunning', 'local_completiondoctor'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    site_auditor::audit(true);
    $scanid = scan_manager::start_scan((int) $USER->id);
    $scan = scan_manager::get_scan($scanid);
    redirect(
        $url,
        get_string('dashboard:scanqueued', 'local_completiondoctor', $scan->totalitems),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$view = optional_param('view', '', PARAM_ALPHA);
$checkid = optional_param('checkid', '', PARAM_ALPHANUMEXT);
if (!in_array($view, ['', 'critical', 'warning', 'info', 'completionoff', 'waiting', 'healthy'], true)) {
    $view = '';
}

\local_completiondoctor\event\dashboard_viewed::create(['context' => $context])->trigger();
$renderer = $PAGE->get_renderer('local_completiondoctor');
$PAGE->requires->js_call_amd('local_completiondoctor/scan_progress', 'init', ['[data-region="scan-progress"]']);

echo $OUTPUT->header();
echo $renderer->dashboard_header($view, $checkid);

$report = system_report_factory::create(dashboard::class, $context, '', '', 0, ['view' => $view, 'checkid' => $checkid]);
echo $report->output();
echo $OUTPUT->footer();
