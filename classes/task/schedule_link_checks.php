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

namespace local_chronifycompliance\task;

use local_chronifycompliance\links\link_scan;

/**
 * Queues automatic link checks of new courses whose content changed (their cache revision moved
 * since the last check) and then stayed unchanged for a few minutes. Does nothing unless an
 * administrator turned on automatic link checks.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_link_checks extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:schedulelinkchecks', 'local_chronifycompliance');
    }

    /**
     * Queue the checks.
     */
    public function execute(): void {
        $queued = link_scan::queue_changed_courses();
        if ($queued) {
            mtrace("Chronify.Compliance: {$queued} link checks queued.");
        }
    }
}
