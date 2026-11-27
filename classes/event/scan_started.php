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

namespace local_completiondoctor\event;

/**
 * Completion Doctor scan started.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_started extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_completiondoctor_scan';
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:scanstarted', 'local_completiondoctor');
    }

    /**
     * Description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' started the Completion Doctor scan with id '{$this->objectid}'.";
    }

    /**
     * Link to the related page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/completiondoctor/index.php');
    }

    /**
     * Mapping of the object id for backup and restore: not restored.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_completiondoctor_scan', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
