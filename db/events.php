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
 * Event observers for Completion Doctor.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [];
foreach (
    [
    '\core\event\course_module_created',
    '\core\event\course_module_updated',
    '\core\event\course_module_deleted',
    '\core\event\course_completion_updated',
    '\core\event\course_restored',
    '\core\event\course_updated',
    ] as $eventname
) {
    $observers[] = [
        'eventname' => $eventname,
        'callback' => '\local_completiondoctor\observer::course_changed',
    ];
}
$observers[] = [
    'eventname' => '\core\event\course_completion_updated',
    'callback' => '\local_completiondoctor\observer::criteria_saved',
];
$observers[] = [
    'eventname' => '\core\event\course_deleted',
    'callback' => '\local_completiondoctor\observer::course_deleted',
];
