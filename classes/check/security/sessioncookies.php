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
 * Session cookies that scripts can read (no HttpOnly), and sessions that stay signed in for more than a day. One
 * click fix for HttpOnly cookies.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sessioncookies extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'sessioncookies';
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
        $findings = [];
        // Moodle sets HttpOnly by default; it is off only on old upgraded sites or when config.php turns it off.
        if (isset($CFG->cookiehttponly) && empty($CFG->cookiehttponly) && isset($CFG->config_php_settings['cookiehttponly'])) {
            $findings[] = $this->security_finding(
                'check:sessioncookies:messagehttponlyconfig',
                [],
                self::settings('httpsecurity'),
                ['case' => 'httponly']
            );
        } else if (isset($CFG->cookiehttponly) && empty($CFG->cookiehttponly)) {
            $findings[] = $this->setting_finding(
                'check:sessioncookies:messagehttponly',
                [],
                self::settings('httpsecurity'),
                'cookiehttponly',
                null,
                '1',
                'fix:setting:cookiehttponly',
                ['case' => 'httponly']
            );
        }
        $timeout = (int) ($CFG->sessiontimeout ?? 0);
        if ($timeout > DAYSECS) {
            $findings[] = $this->security_finding(
                'check:sessioncookies:messagetimeout',
                ['time' => format_time($timeout)],
                self::settings('sessionhandling'),
                ['case' => 'timeout'],
                self::SEVERITY_INFO
            );
        }
        return $findings;
    }
}
