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
 * A change made by a fix, with what is needed to undo it.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixlog_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_chronifycompliance_fixlog';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'findingid' => ['type' => PARAM_INT],
            'courseid' => ['type' => PARAM_INT],
            'cmid' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'checkid' => ['type' => PARAM_ALPHANUMEXT],
            'fixtype' => ['type' => PARAM_ALPHANUMEXT],
            'userid' => ['type' => PARAM_INT],
            'valuebefore' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'valueafter' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'undone' => ['type' => PARAM_INT, 'default' => 0],
            'timeundone' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'undoneby' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
        ];
    }
}
