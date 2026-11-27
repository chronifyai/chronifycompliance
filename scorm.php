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
 * SCORM analysis panel of one SCORM activity.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_completiondoctor\scorm\inspector;

require(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'scorm');
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/completiondoctor:viewcoursereport', $context);

$url = new moodle_url('/local/completiondoctor/scorm.php', ['cmid' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_context(context_module::instance($cm->id));
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('panel:title', 'local_completiondoctor') . ': ' . $cm->get_formatted_name());
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

// The page only reads: analyzing a package, or analyzing it again, runs in a background task.
if ($action === 'refresh' || $action === 'analyze') {
    require_sesskey();
    \local_completiondoctor\task\check_course::request((int) $course->id, (int) $cm->id, $action === 'refresh');
    \local_completiondoctor\event\course_check_requested::create(['context' => $context])->trigger();
    redirect($url, get_string('panel:analysisqueued', 'local_completiondoctor'), null, \core\output\notification::NOTIFY_INFO);
}
$inspection = inspector::inspect($cm, inspector::MODE_READ);
\local_completiondoctor\event\scorm_analysis_viewed::create(['context' => context_module::instance($cm->id)])->trigger();

$PAGE->requires->js_call_amd('local_completiondoctor/course_report', 'init', ['[data-region="scorm-panel"]']);
if (\local_completiondoctor\local\audit_store::get_requested((int) $course->id)) {
    $PAGE->requires->js_call_amd('local_completiondoctor/course_status', 'init', [(int) $course->id]);
}

$renderer = $PAGE->get_renderer('local_completiondoctor');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('panel:title', 'local_completiondoctor') . ': ' . $cm->get_formatted_name());
echo $renderer->scorm_panel($cm, $inspection);
echo $OUTPUT->footer();
