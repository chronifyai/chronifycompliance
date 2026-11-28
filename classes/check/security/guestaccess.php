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
 * Guest access: visitors are signed in as guest automatically, the login page offers guest access, or courses
 * let guests in without a password.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guestaccess extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'guestaccess';
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
        if (!empty($CFG->autologinguests)) {
            $findings[] = $this->security_finding(
                'check:guestaccess:messageauto',
                [],
                self::settings('sitepolicies'),
                ['case' => 'auto']
            );
        }
        if (!empty($CFG->guestloginbutton)) {
            $findings[] = $this->security_finding(
                'check:guestaccess:messagebutton',
                [],
                self::settings('manageauths'),
                ['case' => 'button'],
                self::SEVERITY_INFO
            );
        }
        if (enrol_is_enabled('guest')) {
            $courses = $DB->count_records_select(
                'enrol',
                "enrol = 'guest' AND status = :enabled AND (password IS NULL OR password = '') AND courseid <> :siteid",
                ['enabled' => ENROL_INSTANCE_ENABLED, 'siteid' => SITEID]
            );
            if ($courses) {
                $findings[] = $this->security_finding(
                    'check:guestaccess:messagecourses',
                    ['count' => $courses],
                    self::settings('enrolsettingsguest'),
                    ['case' => 'courses'],
                    self::SEVERITY_INFO
                );
            }
        }
        return $findings;
    }
}
