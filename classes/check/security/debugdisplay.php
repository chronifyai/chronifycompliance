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
 * Debug messages are shown on the page to everyone, which can reveal paths, database details and code. One click
 * fix: turn off Display debug messages (undo turns it back on).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debugdisplay extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'debugdisplay';
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
        if (empty($CFG->debugdisplay)) {
            return [];
        }
        $developer = (int) ($CFG->debug ?? 0) >= DEBUG_DEVELOPER;
        $severity = $developer ? self::SEVERITY_CRITICAL : null;
        // Set in config.php: the settings page cannot change it, so there is no one click fix.
        if (isset($CFG->config_php_settings['debugdisplay'])) {
            return [$this->security_finding('check:debugdisplay:messageconfig', [], self::settings('debugging'), [], $severity)];
        }
        return [$this->setting_finding(
            $developer ? 'check:debugdisplay:messagedeveloper' : 'check:debugdisplay:message',
            [],
            self::settings('debugging'),
            'debugdisplay',
            null,
            '0',
            'fix:setting:debugdisplay',
            [],
            $severity
        )];
    }
}
