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
 * Guided fix: a short wizard for a fix that needs decisions (password policy, MFA), then a review of the exact
 * changes and Apply. The change goes through the fixer, so it is logged and can be undone.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\guided_fix;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\form\guided_form;
use local_chronifycompliance\local\finding_store;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$step = optional_param('step', 0, PARAM_INT);
admin_externalpage_setup('local_chronifycompliance_security');
$component = 'local_chronifycompliance';
$context = context_system::instance();
require_capability('local/chronifycompliance:applyfix', $context);

$finding = finding_store::get($id);
$check = check_registry::get_check($finding->checkid);
if (!$check instanceof guided_fix || !$finding->is_open() || (int) $finding->courseid !== (int) SITEID) {
    throw new moodle_exception('guided:error:notguided', $component);
}
$security = new moodle_url('/local/chronifycompliance/security.php');
$url = new moodle_url('/local/chronifycompliance/guided.php', ['id' => $id]);
$PAGE->set_url(new moodle_url($url, ['step' => $step]));
$PAGE->set_title($check->get_name());

// Answers are kept in the session until the change is applied or the wizard is left.
$answers = $SESSION->local_chronifycompliance_guided[$id] ?? [];
$fields = array_column($check->get_fix_parameters($finding), null, 'name');
foreach ($fields as $name => $field) {
    $answers[$name] = $answers[$name] ?? $field['default'];
}
$steps = $check->get_guided_steps($finding);
$review = count($steps);
$step = max(0, min($review, $step));

if ($step < $review) {
    $current = $steps[$step];
    $form = new guided_form(new moodle_url($url, ['step' => $step]), [
        'id' => $id,
        'step' => $step,
        'steps' => $review + 1,
        'title' => get_string($current['title'], $component),
        'intro' => get_string($current['intro'], $component),
        'fields' => array_values(array_intersect_key($fields, array_flip($current['fields']))),
        'values' => $answers,
    ]);
    if ($form->is_cancelled()) {
        unset($SESSION->local_chronifycompliance_guided[$id]);
        redirect($security);
    }
    if ($form->no_submit_button_pressed()) {
        redirect(new moodle_url($url, ['step' => max(0, $step - 1)]));
    }
    if ($data = $form->get_data()) {
        foreach ($current['fields'] as $name) {
            $answers[$name] = $data->$name ?? 0;
        }
        $SESSION->local_chronifycompliance_guided[$id] = $answers;
        redirect(new moodle_url($url, ['step' => $step + 1]));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading($check->get_name());
    echo html_writer::tag('p', $finding->get_message());
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Review step: the exact changes, then Apply.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'apply') {
    require_sesskey();
    try {
        $result = fixer::apply($id, $answers, (int) $USER->id);
    } catch (moodle_exception $e) {
        redirect(new moodle_url($url, ['step' => $review]), $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    unset($SESSION->local_chronifycompliance_guided[$id]);
    $SESSION->local_chronifycompliance_lastfix = ['fixlogid' => $result->fixlogid, 'message' => $result->message];
    redirect($security);
}
$error = '';
try {
    $preview = fixer::preview($id, $answers);
} catch (moodle_exception $e) {
    $preview = null;
    $error = $e->getMessage();
}

echo $OUTPUT->header();
echo $OUTPUT->heading($check->get_name());
echo $OUTPUT->render_from_template('local_chronifycompliance/guided_review', [
    'stepof' => get_string('setup:stepof', $component, ['step' => $review + 1, 'steps' => $review + 1]),
    'rows' => $preview ? $preview->rows : [],
    'hasrows' => $preview && $preview->rows,
    'warnings' => $preview ? $preview->warnings : [],
    'error' => $error,
    'canapply' => $preview !== null,
    'actionurl' => (new moodle_url($url, ['step' => $review]))->out(false),
    'backurl' => (new moodle_url($url, ['step' => $review - 1]))->out(false),
    'cancelurl' => $security->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
