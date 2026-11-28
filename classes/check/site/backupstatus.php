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

namespace local_chronifycompliance\check\site;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_check;

/**
 * Moodle's automated course backups are off (Info: many sites back up the server instead), or some courses
 * failed their last automated backup (Warning). Reads Moodle's backup status table.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backupstatus extends site_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'backupstatus';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $CFG, $DB;
        $url = self::settings('automated');
        if (empty(get_config('backup', 'backup_auto_active'))) {
            return [$this->security_finding('check:backupstatus:messageoff', [], $url, ['case' => 'off'], self::SEVERITY_INFO)];
        }
        $failed = $DB->count_records_select('backup_courses', 'laststatus IN (:error, :unfinished)', ['error' => '0',
            'unfinished' => '2']);
        if (!$failed) {
            return [];
        }
        return [$this->security_finding(
            'check:backupstatus:messagefailed',
            ['count' => $failed],
            new \moodle_url('/report/backups/index.php'),
            ['case' => 'failed']
        )];
    }
}
