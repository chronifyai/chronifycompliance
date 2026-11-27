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

namespace local_completiondoctor\task;

use local_completiondoctor\check\site_auditor;
use local_completiondoctor\local\scan_manager;

/**
 * Scheduled task running the site checks and queueing an audit of every course.
 *
 * Disabled by default; administrators enable it.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_site extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:scansite', 'local_completiondoctor');
    }

    /**
     * Run the site checks and queue the course audits.
     */
    public function execute(): void {
        if (scan_manager::is_paused()) {
            mtrace('Completion Doctor scans are paused.');
            return;
        }
        $active = scan_manager::get_active_scan();
        if ($active) {
            mtrace("Scan {$active->id} is still in progress, not starting another one.");
            return;
        }
        $result = site_auditor::audit(true);
        mtrace('Site checks: ' . count($result->findings) . ' findings.');
        $scanid = scan_manager::start_scan(0);
        $scan = scan_manager::get_scan($scanid);
        mtrace("Scan {$scanid} queued {$scan->totalitems} courses.");
    }
}
