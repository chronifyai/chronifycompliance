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
 * Web service functions of Completion Doctor.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_completiondoctor_audit_course' => [
        'classname' => \local_completiondoctor\external\audit_course::class,
        'description' => 'Audit a course and return its summary and open findings.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewcoursereport',
    ],
    'local_completiondoctor_preview_fix' => [
        'classname' => \local_completiondoctor\external\preview_fix::class,
        'description' => 'Preview the fix of a finding.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:applyfix',
    ],
    'local_completiondoctor_apply_fix' => [
        'classname' => \local_completiondoctor\external\apply_fix::class,
        'description' => 'Apply the fix of a finding.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:applyfix',
    ],
    'local_completiondoctor_undo_fix' => [
        'classname' => \local_completiondoctor\external\undo_fix::class,
        'description' => 'Undo a fix.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:applyfix',
    ],
    'local_completiondoctor_ignore_finding' => [
        'classname' => \local_completiondoctor\external\ignore_finding::class,
        'description' => 'Ignore a finding with a reason, or reopen it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:ignorefinding',
    ],
    'local_completiondoctor_get_scan_status' => [
        'classname' => \local_completiondoctor\external\get_scan_status::class,
        'description' => 'Progress of the latest site scan.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewsitereport',
    ],
    'local_completiondoctor_get_course_status' => [
        'classname' => \local_completiondoctor\external\get_course_status::class,
        'description' => 'Whether a requested check of a course is still waiting.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewcoursereport',
    ],
    'local_completiondoctor_get_findings' => [
        'classname' => \local_completiondoctor\external\get_findings::class,
        'description' => 'Findings across the site, filtered by category, severity, check and status, paged.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewsitereport',
    ],
    'local_completiondoctor_analyze_scorm' => [
        'classname' => \local_completiondoctor\external\analyze_scorm::class,
        'description' => 'Analyze the package of a SCORM activity, with what learners reported and recommended settings.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewcoursereport',
    ],
    'local_completiondoctor_analyze_package' => [
        'classname' => \local_completiondoctor\external\analyze_package::class,
        'description' => 'Analyze a SCORM package uploaded to the draft area, without a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewsitereport',
    ],
    'local_completiondoctor_explain_user_completion' => [
        'classname' => \local_completiondoctor\external\explain_user_completion::class,
        'description' => 'Explain the course completion of an enrolled learner: conditions, state and what blocks it.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/completiondoctor:viewlearnerdiagnostic',
    ],
];

$services = [
    'Completion Doctor API' => [
        'shortname' => 'local_completiondoctor',
        'functions' => [
            'local_completiondoctor_audit_course',
            'local_completiondoctor_get_findings',
            'local_completiondoctor_analyze_scorm',
            'local_completiondoctor_analyze_package',
            'local_completiondoctor_preview_fix',
            'local_completiondoctor_apply_fix',
            'local_completiondoctor_undo_fix',
            'local_completiondoctor_ignore_finding',
            'local_completiondoctor_explain_user_completion',
            'local_completiondoctor_get_scan_status',
            'local_completiondoctor_get_course_status',
            'core_files_upload',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'downloadfiles' => 0,
        'uploadfiles' => 1,
    ],
];
