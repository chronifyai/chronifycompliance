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
 * A link check of a course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linkcheck_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_chronifycompliance_linkcheck';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'courseid' => ['type' => PARAM_INT],
            'status' => ['type' => PARAM_ALPHA, 'default' => 'queued'],
            'requestedby' => ['type' => PARAM_INT, 'default' => 0],
            'runat' => ['type' => PARAM_INT, 'default' => 0],
            'cacherev' => ['type' => PARAM_INT, 'default' => 0],
            'totalitems' => ['type' => PARAM_INT, 'default' => 0],
            'processed' => ['type' => PARAM_INT, 'default' => 0],
            'skippedfiles' => ['type' => PARAM_INT, 'default' => 0],
            'truncated' => ['type' => PARAM_INT, 'default' => 0],
            'timestarted' => ['type' => PARAM_INT, 'default' => 0],
            'timefinished' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
