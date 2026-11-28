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

namespace local_chronifycompliance\persistent;

use core\persistent;

/**
 * Last audit of a course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_chronifycompliance_audit';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'courseid' => ['type' => PARAM_INT],
            'timeaudited' => ['type' => PARAM_INT, 'default' => 0],
            'duration' => ['type' => PARAM_INT, 'default' => 0],
            'queries' => ['type' => PARAM_INT, 'default' => 0],
            'critical' => ['type' => PARAM_INT, 'default' => 0],
            'warning' => ['type' => PARAM_INT, 'default' => 0],
            'info' => ['type' => PARAM_INT, 'default' => 0],
            'checkerrors' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'criteriasaved' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'timerequested' => ['type' => PARAM_INT, 'default' => 0],
            'waiting' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
