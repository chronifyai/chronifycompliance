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
 * First run wizard: your site, frameworks, start the first check.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_chronifycompliance\form\setup_form;
use local_chronifycompliance\local\setup;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_chronifycompliance_setup');
require_capability('local/chronifycompliance:runscan', context_system::instance());

$component = 'local_chronifycompliance';
$step = min(3, max(1, optional_param('step', 1, PARAM_INT)));
$url = new moodle_url('/local/chronifycompliance/setup.php');
$dashboard = new moodle_url('/local/chronifycompliance/index.php');
$PAGE->set_title(get_string('setup', $component));

$form = new setup_form(new moodle_url($url, ['step' => $step]), ['step' => $step]);
if ($form->no_submit_button_pressed()) {
    require_sesskey();
    if (optional_param('skip', '', PARAM_RAW) !== '') {
        setup::skip();
        redirect($dashboard);
    }
    redirect(new moodle_url($url, ['step' => max(1, $step - 1)]));
}
if ($data = $form->get_data()) {
    if ($step === 1) {
        setup::save_site($data->sector, $data->country);
    } else if ($step === 2) {
        setup::save_frameworks(array_filter(setup::FRAMEWORKS, fn($f) => !empty($data->{'fw_' . $f})));
    } else {
        $scanid = setup::start((int) $USER->id);
        redirect(
            $dashboard,
            $scanid ? get_string('setup:started', $component) : get_string('dashboard:scanrunning', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(new moodle_url($url, ['step' => $step + 1]));
}
if ($step > 1 && setup::get_sector() === '') {
    redirect(new moodle_url($url, ['step' => 1]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setup', $component));
$form->display();
echo $OUTPUT->footer();
