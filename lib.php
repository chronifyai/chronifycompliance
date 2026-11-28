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
 * Library callbacks for Chronify.Compliance.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Site checks of the given pack as core checks, without the ones that read core's own checks (that would
 * run them in a loop).
 *
 * @param string $pack
 * @return \core\check\check[]
 */
function local_chronifycompliance_core_checks(string $pack): array {
    $checks = [];
    $sitechecks = \local_chronifycompliance\check\check_registry::get_checks(
        [\local_chronifycompliance\check\base_check::SCOPE_SITE],
        false,
        [$pack]
    );
    foreach ($sitechecks as $check) {
        if ($check instanceof \local_chronifycompliance\check\core_import_check) {
            continue;
        }
        $checks[] = new \local_chronifycompliance\check\core_check_adapter($check);
    }
    return $checks;
}

/**
 * Site and performance checks shown on Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function local_chronifycompliance_status_checks(): array {
    return local_chronifycompliance_core_checks(\local_chronifycompliance\check\base_check::PACK_SITE);
}

/**
 * Security checks shown on Site administration > Reports > Security checks.
 *
 * @return \core\check\check[]
 */
function local_chronifycompliance_security_checks(): array {
    return local_chronifycompliance_core_checks(\local_chronifycompliance\check\base_check::PACK_SECURITY);
}

/**
 * Add "Completion health" to the course navigation (the "More" menu in Boost).
 *
 * @param navigation_node $navigation Course administration node.
 * @param stdClass $course
 * @param context $context
 */
function local_chronifycompliance_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if ((int) $course->id === (int) SITEID || !has_capability('local/chronifycompliance:viewcoursereport', $context)) {
        return;
    }
    $navigation->add(
        get_string('report:navigation', 'local_chronifycompliance'),
        new moodle_url('/local/chronifycompliance/course.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_chronifycompliance_report',
        new pix_icon('i/report', '')
    );
}

/**
 * User preferences of the plugin: when each invitation was dismissed.
 *
 * @return array
 */
function local_chronifycompliance_user_preferences(): array {
    $preferences = [];
    foreach (\local_chronifycompliance\output\invitation::KEYS as $key) {
        $preferences[\local_chronifycompliance\output\invitation::preference($key)] = [
            'type' => PARAM_INT,
            'null' => NULL_NOT_ALLOWED,
            'default' => 0,
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ];
    }
    return $preferences;
}
