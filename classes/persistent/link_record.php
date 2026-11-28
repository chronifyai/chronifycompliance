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
 * A link found in a course by a link check.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_chronifycompliance_link';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'courseid' => ['type' => PARAM_INT],
            'cmid' => ['type' => PARAM_INT, 'default' => 0],
            'source' => ['type' => PARAM_ALPHA],
            'itemid' => ['type' => PARAM_INT, 'default' => 0],
            'sourcename' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'url' => ['type' => PARAM_RAW],
            'linktext' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'urlhash' => ['type' => PARAM_ALPHANUM],
            'kind' => ['type' => PARAM_ALPHA],
            'status' => ['type' => PARAM_INT, 'default' => 0],
            'httpcode' => ['type' => PARAM_INT, 'default' => 0],
            'reason' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'timechecked' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
