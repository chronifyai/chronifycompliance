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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\output\finding_exporter;
use local_chronifycompliance\task\check_course;

/**
 * Audit a course (optionally refreshing it first) and return its summary and open findings.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_course extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'refresh' => new external_value(PARAM_BOOL, 'Run a new audit first', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Audit the course.
     *
     * @param int $courseid
     * @param bool $refresh
     * @return array
     */
    public static function execute(int $courseid, bool $refresh = false): array {
        ['courseid' => $courseid, 'refresh' => $refresh] = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'refresh' => $refresh]
        );
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewcoursereport', $context);

        // A check never runs inside the request: it is queued, and the stored result is returned.
        if ($refresh || (!audit_store::get($courseid) && !audit_store::get_requested($courseid))) {
            check_course::request($courseid);
        }
        $summary = finding_store::get_summary($courseid);
        $audit = audit_store::get($courseid);
        $errors = $audit && $audit->checkerrors ? array_keys(json_decode($audit->checkerrors, true)) : [];
        return [
            'courseid' => $courseid,
            'critical' => $summary['critical'],
            'warning' => $summary['warning'],
            'info' => $summary['info'],
            'timeaudited' => $audit ? (int) $audit->timeaudited : 0,
            'checkerrors' => $errors,
            'findings' => array_values(array_map(
                [finding_exporter::class, 'export_for_api'],
                finding_store::get_course_findings($courseid)
            )),
        ];
    }

    /**
     * Structure of an exported finding.
     *
     * @return external_single_structure
     */
    public static function finding_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Finding id'),
            'courseid' => new external_value(PARAM_INT, 'Course id (site id for site findings)'),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_OPTIONAL),
            'checkid' => new external_value(PARAM_ALPHANUMEXT, 'Check id'),
            'checkname' => new external_value(PARAM_TEXT, 'Check name'),
            'severity' => new external_value(PARAM_INT, '1 info, 2 warning, 3 critical'),
            'status' => new external_value(PARAM_ALPHA, 'open, ignored, resolved or fixed'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'fixable' => new external_value(PARAM_BOOL, 'Whether a fix is available'),
            'details' => new external_value(PARAM_RAW, 'JSON details'),
            'timecreated' => new external_value(PARAM_INT, 'First detected'),
            'lastseen' => new external_value(PARAM_INT, 'Last detected'),
        ]);
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'critical' => new external_value(PARAM_INT, 'Open critical findings'),
            'warning' => new external_value(PARAM_INT, 'Open warnings'),
            'info' => new external_value(PARAM_INT, 'Open info findings'),
            'timeaudited' => new external_value(PARAM_INT, 'Time of the last audit'),
            'checkerrors' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Check id'),
                'Checks that failed during this audit'
            ),
            'findings' => new external_multiple_structure(self::finding_structure()),
        ]);
    }
}
