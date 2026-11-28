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

namespace local_chronifycompliance\check\security;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\security_check;

/**
 * Web services are on, but no protocol is enabled and the mobile app is off, so nothing can use them. One click
 * fix: turn web services off (undo turns them back on).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservices extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'webservices';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_INFO;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $CFG, $DB;
        if (empty($CFG->enablewebservices) || !empty($CFG->enablemobilewebservice)) {
            return [];
        }
        if (trim((string) ($CFG->webserviceprotocols ?? '')) !== '') {
            return [];
        }
        return [$this->setting_finding(
            'check:webservices:message',
            [],
            new \moodle_url('/admin/category.php', ['category' => 'webservicesettings']),
            'enablewebservices',
            null,
            '0',
            'fix:setting:enablewebservices'
        )];
    }
}
