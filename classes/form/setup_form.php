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

namespace local_chronifycompliance\form;

use local_chronifycompliance\local\setup;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * One step of the first run wizard. Custom data: step (1 your site, 2 frameworks, 3 start).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_form extends \moodleform {
    /**
     * Elements of the step.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $component = 'local_chronifycompliance';
        $step = (int) $this->_customdata['step'];
        $mform->addElement('hidden', 'step', $step);
        $mform->setType('step', PARAM_INT);
        $mform->addElement('html', \html_writer::tag(
            'p',
            get_string('setup:stepof', $component, ['step' => $step, 'steps' => 3]),
            ['class' => 'text-muted small mb-1']
        ));
        $mform->addElement('header', 'stepheader', get_string('setup:step' . $step, $component));
        $mform->setExpanded('stepheader');

        if ($step === 1) {
            $mform->addElement('html', \html_writer::tag('p', get_string('setup:step1:help', $component)));
            $sectors = ['' => get_string('choosedots')];
            foreach (setup::SECTORS as $sector) {
                $sectors[$sector] = get_string('setup:sector:' . $sector, $component);
            }
            $mform->addElement('select', 'sector', get_string('setup:sector', $component), $sectors);
            $mform->addRule('sector', null, 'required', null, 'client');
            $mform->setDefault('sector', setup::get_sector());
            $countries = ['' => get_string('selectacountry')] + get_string_manager()->get_list_of_countries();
            $mform->addElement('select', 'country', get_string('country'), $countries);
            $mform->setDefault('country', setup::get_country());
        } else if ($step === 2) {
            $mform->addElement('html', \html_writer::tag('p', get_string('setup:step2:help', $component)));
            $chosen = setup::get_frameworks();
            foreach (setup::FRAMEWORKS as $framework) {
                $mform->addElement(
                    'advcheckbox',
                    'fw_' . $framework,
                    get_string('framework:' . $framework, $component),
                    get_string('framework:' . $framework . ':desc', $component)
                );
                $mform->setDefault('fw_' . $framework, in_array($framework, $chosen, true) ? 1 : 0);
            }
            $soon = array_map(fn($f) => get_string('framework:' . $f, $component), setup::SOON);
            $mform->addElement('static', 'soon', get_string('setup:soon', $component), implode(', ', $soon));
        } else {
            $mform->addElement('html', \html_writer::tag('p', get_string('setup:step3:help', $component)));
            $mform->addElement('html', \html_writer::alist([
                get_string('setup:step3:quick', $component, setup::SAMPLE),
                get_string('setup:step3:full', $component),
                get_string('setup:step3:readonly', $component),
            ]));
        }

        // Back and Skip leave the step without checking its fields, as Moodle does for its own no-submit buttons.
        $nosubmit = ['data-skip-validation' => 1, 'data-no-submit' => 1, 'onclick' => 'skipClientValidation = true;'];
        $buttons = [];
        if ($step > 1) {
            $buttons[] = $mform->createElement('submit', 'back', get_string('back'), $nosubmit);
        }
        $buttons[] = $mform->createElement(
            'submit',
            'next',
            get_string($step === 3 ? 'setup:start' : 'next', $step === 3 ? $component : 'moodle')
        );
        $buttons[] = $mform->createElement('submit', 'skip', get_string('setup:skip', $component), $nosubmit);
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
        foreach (['back', 'skip'] as $name) {
            if ($step > 1 || $name === 'skip') {
                $mform->registerNoSubmitButton($name);
            }
        }
    }
}
