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
 * Chronify.Compliance SCORM package analyzed.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_analyzed extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_chronifycompliance_pkg';
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:packageanalyzed', 'local_chronifycompliance');
    }

    /**
     * Description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "Chronify.Compliance analyzed the SCORM package with content hash '{$this->other['contenthash']}'"
            . " (result '{$this->other['status']}').";
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['contenthash'])) {
            throw new \coding_exception('The \'contenthash\' value must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup and restore: not restored.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_chronifycompliance_pkg', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other data for backup and restore: nothing to map.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
