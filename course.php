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
 * Course completion health report.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\links\link_scan;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\task\check_course;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chronifycompliance:viewcoursereport', $context);

$url = new moodle_url('/local/chronifycompliance/course.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('report:title', 'local_chronifycompliance') . ': ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));

// Every action only queues work: the check runs in a background task and the page waits for it.
if ($action === 'recheck' || $action === 'analyzescorm') {
    require_sesskey();
    $cmid = 0;
    if ($action === 'analyzescorm') {
        $cm = get_fast_modinfo($course)->get_cm(required_param('cmid', PARAM_INT));
        if ($cm->modname !== 'scorm') {
            throw new moodle_exception('invalidcoursemodule');
        }
        $cmid = (int) $cm->id;
    }
    check_course::request((int) $course->id, $cmid);
    \local_chronifycompliance\event\course_check_requested::create(['context' => $context])->trigger();
    redirect($url, get_string('report:checkqueued', 'local_chronifycompliance'), null, \core\output\notification::NOTIFY_INFO);
}

if ($action === 'checklinks') {
    require_sesskey();
    require_capability('local/chronifycompliance:checklinks', $context);
    if (!link_scan::is_enabled()) {
        throw new moodle_exception('links:disabled', 'local_chronifycompliance', $url);
    }
    $queued = link_scan::queue((int) $course->id, (int) $USER->id);
    redirect(
        new moodle_url($url, [], 'cd-links'),
        get_string($queued ? 'links:queued' : 'links:alreadyqueued', 'local_chronifycompliance'),
        null,
        $queued ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}

// First visit: queue the first check instead of running it here.
if (!audit_store::get((int) $course->id) && !audit_store::get_requested((int) $course->id)) {
    check_course::request((int) $course->id);
}
\local_chronifycompliance\event\course_report_viewed::create(['context' => $context])->trigger();

$PAGE->requires->js_call_amd('local_chronifycompliance/course_report', 'init', ['[data-region="course-report"]']);
if (audit_store::get_requested((int) $course->id)) {
    $PAGE->requires->js_call_amd('local_chronifycompliance/course_status', 'init', [(int) $course->id]);
}

$renderer = $PAGE->get_renderer('local_chronifycompliance');
echo $OUTPUT->header();
echo $renderer->course_report($course);
echo $OUTPUT->footer();
