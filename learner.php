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
 * Learner completion diagnostic.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\learner\explainer;

require(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_NOTAGS));

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chronifycompliance:viewlearnerdiagnostic', $context);

$url = new moodle_url('/local/chronifycompliance/learner.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('learner:title', 'local_chronifycompliance') . ': ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));

// Only users enrolled in the course (active or not) can be diagnosed, in separate groups only those
// of the viewer's groups.
$matches = $search !== '' ? explainer::search_learners($course, $search) : [];

$user = null;
$explanation = null;
if ($userid) {
    explainer::require_can_diagnose($course, $userid);
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    $explanation = explainer::explain((int) $course->id, $userid);
    \local_chronifycompliance\event\learner_diagnostic_viewed::create(['context' => $context, 'relateduserid' => $userid])
        ->trigger();
}

$renderer = $PAGE->get_renderer('local_chronifycompliance');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('learner:title', 'local_chronifycompliance'));
echo $renderer->learner_report($course, $user, $explanation, array_values($matches), $search);
echo $OUTPUT->footer();
