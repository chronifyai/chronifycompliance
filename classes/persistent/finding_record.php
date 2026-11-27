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

namespace local_completiondoctor\persistent;

use core\persistent;

/**
 * A finding of a check, one row per fingerprint.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_completiondoctor_finding';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'courseid' => ['type' => PARAM_INT],
            'cmid' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'checkid' => ['type' => PARAM_ALPHANUMEXT],
            'component' => ['type' => PARAM_COMPONENT],
            'severity' => ['type' => PARAM_INT, 'default' => 1],
            'baseseverity' => ['type' => PARAM_INT, 'default' => 1],
            'fingerprint' => ['type' => PARAM_ALPHANUM],
            'status' => ['type' => PARAM_ALPHA, 'default' => 'open'],
            'messagekey' => ['type' => PARAM_RAW],
            'messageparams' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'details' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'fixable' => ['type' => PARAM_INT, 'default' => 0],
            'ignorereason' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'ignoredby' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'timeignored' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'lastseen' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
