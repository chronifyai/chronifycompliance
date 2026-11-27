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

namespace local_chronifycompliance\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_chronifycompliance\scorm\package_analyzer;

/**
 * Analyze a SCORM package uploaded to the user's draft area, without a course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_package extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(
                PARAM_INT,
                'Draft area item id holding the zip (for example from core_files_upload)'
            ),
        ]);
    }

    /**
     * Analyze the uploaded package.
     *
     * @param int $draftitemid
     * @return array
     */
    public static function execute(int $draftitemid): array {
        global $USER;
        ['draftitemid' => $draftitemid] = self::validate_parameters(
            self::execute_parameters(),
            ['draftitemid' => $draftitemid]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewsitereport', $context);

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );
        $file = null;
        foreach ($files as $candidate) {
            if (strtolower(pathinfo($candidate->get_filename(), PATHINFO_EXTENSION)) === 'zip') {
                $file = $candidate;
                break;
            }
        }
        if (!$file) {
            throw new \invalid_parameter_exception('No zip file in the draft area');
        }
        // Uploaded packages are analyzed in the request, so only up to the inline size, and never stored.
        if ($file->get_filesize() > package_analyzer::get_inline_bytes()) {
            throw new \moodle_exception(
                'error:packagetoolarge',
                'local_chronifycompliance',
                '',
                display_size(package_analyzer::get_inline_bytes())
            );
        }
        $package = package_analyzer::analyze_stored_file($file, true, false);
        return scorm_exporter::export($package);
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return scorm_exporter::structure();
    }
}
