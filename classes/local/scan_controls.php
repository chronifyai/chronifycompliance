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

namespace local_chronifycompliance\local;

use moodle_url;

/**
 * Start and cancel buttons of the site scan, shared by the dashboard and the Completion workspace.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_controls {
    /**
     * Handle the scan and cancel actions of a page. Redirects back to the page when an action ran.
     *
     * @param string $action 'scan', 'cancel' or anything else (ignored).
     * @param moodle_url $url Page to return to.
     */
    public static function handle(string $action, moodle_url $url): void {
        global $USER;
        if (!in_array($action, ['scan', 'cancel'], true)) {
            return;
        }
        require_sesskey();
        require_capability('local/chronifycompliance:runscan', \context_system::instance());
        $component = 'local_chronifycompliance';
        if ($action === 'cancel') {
            $active = scan_manager::get_active_scan();
            if ($active) {
                scan_manager::cancel((int) $active->id);
            }
            redirect($url, get_string('dashboard:scancancelled', $component), null, \core\output\notification::NOTIFY_INFO);
        }
        if (scan_manager::get_active_scan()) {
            redirect($url, get_string('dashboard:scanrunning', $component), null, \core\output\notification::NOTIFY_WARNING);
        }
        \local_chronifycompliance\check\site_auditor::audit(true);
        $scanid = scan_manager::start_scan((int) $USER->id);
        $scan = scan_manager::get_scan($scanid);
        redirect(
            $url,
            get_string('dashboard:scanqueued', $component, $scan->totalitems),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}
