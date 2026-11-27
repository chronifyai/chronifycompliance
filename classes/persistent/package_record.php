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
 * Analysis of a SCORM package file, also the analysis queue (status "queued").
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_record extends persistent {
    /** @var string Table. */
    public const TABLE = 'local_completiondoctor_pkg';

    /**
     * Properties of the record.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'contenthash' => ['type' => PARAM_ALPHANUM],
            'parserversion' => ['type' => PARAM_INT, 'default' => 0],
            'status' => ['type' => PARAM_ALPHA, 'default' => 'ok'],
            'error' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'filesize' => ['type' => PARAM_INT, 'default' => 0],
            'scormversion' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'scocount' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'assetcount' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'tool' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'toolversion' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'toolconfidence' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'reportingmode' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'masteryscore' => ['type' => PARAM_FLOAT, 'default' => null, 'null' => NULL_ALLOWED],
            'completionthreshold' => ['type' => PARAM_FLOAT, 'default' => null, 'null' => NULL_ALLOWED],
            'stagewidth' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'stageheight' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'fluid' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'totalbytes' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'startupbytes' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'maxslidebytes' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'filecount' => ['type' => PARAM_INT, 'default' => null, 'null' => NULL_ALLOWED],
            'maxvideokbps' => ['type' => PARAM_FLOAT, 'default' => null, 'null' => NULL_ALLOWED],
            'minmbps' => ['type' => PARAM_FLOAT, 'default' => null, 'null' => NULL_ALLOWED],
            'analysis' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'attempts' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
