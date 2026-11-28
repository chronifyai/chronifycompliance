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

/**
 * Web service functions of Chronify.Compliance.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_chronifycompliance_audit_course' => [
        'classname' => \local_chronifycompliance\external\audit_course::class,
        'description' => 'Audit a course and return its summary and open findings.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewcoursereport',
    ],
    'local_chronifycompliance_preview_fix' => [
        'classname' => \local_chronifycompliance\external\preview_fix::class,
        'description' => 'Preview the fix of a finding.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:applyfix',
    ],
    'local_chronifycompliance_apply_fix' => [
        'classname' => \local_chronifycompliance\external\apply_fix::class,
        'description' => 'Apply the fix of a finding.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:applyfix',
    ],
    'local_chronifycompliance_undo_fix' => [
        'classname' => \local_chronifycompliance\external\undo_fix::class,
        'description' => 'Undo a fix.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:applyfix',
    ],
    'local_chronifycompliance_preview_bulk_fix' => [
        'classname' => \local_chronifycompliance\external\preview_bulk_fix::class,
        'description' => 'Preview the one click fixes of many courses.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewsitereport, local/chronifycompliance:applyfix',
    ],
    'local_chronifycompliance_apply_bulk_fix' => [
        'classname' => \local_chronifycompliance\external\apply_bulk_fix::class,
        'description' => 'Apply the one click fixes of many courses shown in the preview.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewsitereport, local/chronifycompliance:applyfix',
    ],
    'local_chronifycompliance_request_refresh' => [
        'classname' => \local_chronifycompliance\external\request_refresh::class,
        'description' => 'Queue a background refresh of one dataset of the data layer.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:runscan',
    ],
    'local_chronifycompliance_ignore_finding' => [
        'classname' => \local_chronifycompliance\external\ignore_finding::class,
        'description' => 'Ignore a finding with a reason, or reopen it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:ignorefinding',
    ],
    'local_chronifycompliance_get_scan_status' => [
        'classname' => \local_chronifycompliance\external\get_scan_status::class,
        'description' => 'Progress of the latest site scan.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewsitereport',
    ],
    'local_chronifycompliance_get_course_status' => [
        'classname' => \local_chronifycompliance\external\get_course_status::class,
        'description' => 'Whether a requested check of a course is still waiting.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewcoursereport',
    ],
    'local_chronifycompliance_get_findings' => [
        'classname' => \local_chronifycompliance\external\get_findings::class,
        'description' => 'Findings across the site, filtered by category, severity, check and status, paged.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewsitereport',
    ],
    'local_chronifycompliance_analyze_scorm' => [
        'classname' => \local_chronifycompliance\external\analyze_scorm::class,
        'description' => 'Analyze the package of a SCORM activity, with what learners reported and recommended settings.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewcoursereport',
    ],
    'local_chronifycompliance_analyze_package' => [
        'classname' => \local_chronifycompliance\external\analyze_package::class,
        'description' => 'Analyze a SCORM package uploaded to the draft area, without a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewsitereport',
    ],
    'local_chronifycompliance_explain_user_completion' => [
        'classname' => \local_chronifycompliance\external\explain_user_completion::class,
        'description' => 'Explain the course completion of an enrolled learner: conditions, state and what blocks it.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/chronifycompliance:viewlearnerdiagnostic',
    ],
];

$services = [
    'Chronify.Compliance API' => [
        'shortname' => 'local_chronifycompliance',
        'functions' => [
            'local_chronifycompliance_audit_course',
            'local_chronifycompliance_get_findings',
            'local_chronifycompliance_analyze_scorm',
            'local_chronifycompliance_analyze_package',
            'local_chronifycompliance_preview_fix',
            'local_chronifycompliance_apply_fix',
            'local_chronifycompliance_undo_fix',
            'local_chronifycompliance_ignore_finding',
            'local_chronifycompliance_explain_user_completion',
            'local_chronifycompliance_get_scan_status',
            'local_chronifycompliance_get_course_status',
            'local_chronifycompliance_request_refresh',
            'local_chronifycompliance_preview_bulk_fix',
            'local_chronifycompliance_apply_bulk_fix',
            'core_files_upload',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'downloadfiles' => 0,
        'uploadfiles' => 1,
    ],
];
