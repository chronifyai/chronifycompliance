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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * One question step of a guided fix. Custom data: id (finding), step (index), steps (count of all steps, review
 * included), title, intro, fields (parameter definitions of the step), values (answers so far).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guided_form extends \moodleform {
    /**
     * Elements of the step.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $data = $this->_customdata;
        $component = 'local_chronifycompliance';
        $mform->addElement('hidden', 'id', $data['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'step', $data['step']);
        $mform->setType('step', PARAM_INT);
        $mform->addElement('html', \html_writer::tag('p', get_string(
            'setup:stepof',
            $component,
            ['step' => $data['step'] + 1, 'steps' => $data['steps']]
        ), ['class' => 'text-muted small mb-1']));
        $mform->addElement('header', 'stepheader', $data['title']);
        $mform->setExpanded('stepheader');
        $mform->addElement('html', \html_writer::tag('p', $data['intro']));
        foreach ($data['fields'] as $field) {
            if ($field['element'] === 'checkbox') {
                $mform->addElement('advcheckbox', $field['name'], $field['label'], $field['help']);
            } else {
                $mform->addElement('text', $field['name'], $field['label'], ['size' => 6]);
                $mform->addElement('static', $field['name'] . '_help', '', \html_writer::span($field['help'], 'small text-muted'));
            }
            $mform->setType($field['name'], $field['type']);
            $mform->setDefault($field['name'], $data['values'][$field['name']] ?? $field['default']);
        }
        $nosubmit = ['data-skip-validation' => 1, 'data-no-submit' => 1, 'onclick' => 'skipClientValidation = true;'];
        $buttons = [];
        if ($data['step'] > 0) {
            $buttons[] = $mform->createElement('submit', 'back', get_string('back'), $nosubmit);
        }
        $buttons[] = $mform->createElement('submit', 'next', get_string('next'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
        if ($data['step'] > 0) {
            $mform->registerNoSubmitButton('back');
        }
    }

    /**
     * Numbers must be in range.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach ($this->_customdata['fields'] as $field) {
            if (
                isset($field['min']) && ((int) ($data[$field['name']] ?? 0) < $field['min']
                    || (int) ($data[$field['name']] ?? 0) > $field['max'])
            ) {
                $errors[$field['name']] = get_string(
                    'guided:error:range',
                    'local_chronifycompliance',
                    ['label' => $field['label'], 'min' => $field['min'], 'max' => $field['max']]
                );
            }
        }
        return $errors;
    }
}
