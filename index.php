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
 * Chronify.Compliance main dashboard: overall health score, pack scores, trend and the top 3 actions.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\local\scan_controls;
use local_chronifycompliance\output\main_dashboard;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_chronifycompliance_dashboard');
$context = context_system::instance();
require_capability('local/chronifycompliance:viewsitereport', $context);
$url = new moodle_url('/local/chronifycompliance/index.php');

scan_controls::handle(optional_param('action', '', PARAM_ALPHA), $url);
if (\local_chronifycompliance\local\setup::needs_wizard() && has_capability('local/chronifycompliance:runscan', $context)) {
    redirect(new moodle_url('/local/chronifycompliance/setup.php'));
}

\local_chronifycompliance\event\dashboard_viewed::create(['context' => $context])->trigger();
$PAGE->requires->js_call_amd('local_chronifycompliance/scan_progress', 'init', ['[data-region="scan-progress"]']);
$PAGE->requires->js_call_amd('local_chronifycompliance/course_report', 'init', ['[data-region="main-dashboard"]']);

echo $OUTPUT->header();
echo $OUTPUT->render(new main_dashboard());
echo $OUTPUT->footer();
