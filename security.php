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
 * Security and Compliance workspace, Security view: every security finding, grouped by area, with one action each.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_chronifycompliance_security');
$context = context_system::instance();
require_capability('local/chronifycompliance:viewsitereport', $context);
$url = new moodle_url('/local/chronifycompliance/security.php');

if (optional_param('action', '', PARAM_ALPHA) === 'check') {
    require_sesskey();
    require_capability('local/chronifycompliance:runscan', $context);
    // The site checks are quick (under a second on large sites), so they run now.
    \local_chronifycompliance\check\site_auditor::audit(true);
    redirect($url, get_string('security:checked', 'local_chronifycompliance'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$filter = optional_param('filter', '', PARAM_ALPHA);
$PAGE->set_url(new moodle_url($url, $filter !== '' ? ['filter' => $filter] : []));
$PAGE->set_title(get_string('nav:security', 'local_chronifycompliance'));
$PAGE->requires->js_call_amd('local_chronifycompliance/course_report', 'init', ['[data-region="security-view"]']);

echo $OUTPUT->header();
echo $OUTPUT->render(new \local_chronifycompliance\output\security_view($filter));
echo $OUTPUT->footer();
