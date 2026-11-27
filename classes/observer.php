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

namespace local_chronifycompliance;

use local_chronifycompliance\fix\fix_log;
use local_chronifycompliance\links\link_scan;
use local_chronifycompliance\links\link_store;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\local\scan_manager;

/**
 * Event observers.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Run an observer body without ever breaking the action that raised the event: core only catches
     * \Exception from observers, so an \Error would stop a course create, edit or restore.
     *
     * @param callable $body
     */
    private static function safely(callable $body): void {
        try {
            $body();
        } catch (\Throwable $e) {
            debugging('Chronify.Compliance observer failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queue a delayed re-audit of the course of the event, when enabled.
     *
     * @param \core\event\base $event
     */
    public static function course_changed(\core\event\base $event): void {
        if (!get_config('local_chronifycompliance', 'autoreaudit')) {
            return;
        }
        self::safely(function () use ($event): void {
            $courseid = (int) $event->courseid;
            if ($courseid && $courseid !== (int) SITEID) {
                scan_manager::queue_course($courseid);
            }
        });
    }

    /**
     * Record when the course completion settings were saved, so checks never need to search the log.
     *
     * @param \core\event\course_completion_updated $event
     */
    public static function criteria_saved(\core\event\course_completion_updated $event): void {
        self::safely(fn() => audit_store::set_criteria_saved((int) $event->courseid, (int) $event->timecreated));
    }

    /**
     * Remove what the plugin stored about a deleted course.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        self::safely(function () use ($event): void {
            $courseid = (int) $event->objectid;
            finding_store::delete_course($courseid);
            fix_log::delete_course($courseid);
            link_store::delete_course($courseid);
            link_scan::delete_course($courseid);
        });
    }
}
