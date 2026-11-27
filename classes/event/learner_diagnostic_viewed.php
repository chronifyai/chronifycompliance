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

namespace local_chronifycompliance\event;

/**
 * Learner completion diagnostic viewed.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_diagnostic_viewed extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:learnerdiagnosticviewed', 'local_chronifycompliance');
    }

    /**
     * Description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' viewed the completion diagnostic of the user with id '{$this->relateduserid}'"
            . " in the course with id '{$this->courseid}'.";
    }

    /**
     * Link to the related page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/chronifycompliance/learner.php', [
            'courseid' => $this->courseid,
            'userid' => $this->relateduserid,
        ]);
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
