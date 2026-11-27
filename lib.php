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
 * Library callbacks for Completion Doctor.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Site checks shown on Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function local_completiondoctor_status_checks(): array {
    $checks = [];
    $sitechecks = \local_completiondoctor\check\check_registry::get_checks([\local_completiondoctor\check\base_check::SCOPE_SITE]);
    foreach ($sitechecks as $check) {
        $checks[] = new \local_completiondoctor\check\core_check_adapter($check);
    }
    return $checks;
}

/**
 * Add "Completion health" to the course navigation (the "More" menu in Boost).
 *
 * @param navigation_node $navigation Course administration node.
 * @param stdClass $course
 * @param context $context
 */
function local_completiondoctor_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if ((int) $course->id === (int) SITEID || !has_capability('local/completiondoctor:viewcoursereport', $context)) {
        return;
    }
    $navigation->add(
        get_string('report:navigation', 'local_completiondoctor'),
        new moodle_url('/local/completiondoctor/course.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_completiondoctor_report',
        new pix_icon('i/report', '')
    );
}
