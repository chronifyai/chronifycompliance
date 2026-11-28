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
 * The password policy is on but weak: short passwords, or no lockout after failed sign ins. Moodle's own
 * security check already reports a policy that is off (`coresecurity`).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passwordstrength extends security_check {
    /** @var int Shortest password length that counts as strong. */
    public const MIN_LENGTH = 8;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'passwordstrength';
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
        global $CFG;
        $findings = [];
        if (!empty($CFG->passwordpolicy) && (int) ($CFG->minpasswordlength ?? 0) < self::MIN_LENGTH) {
            $findings[] = $this->security_finding(
                'check:passwordstrength:messagelength',
                ['length' => (int) ($CFG->minpasswordlength ?? 0), 'min' => self::MIN_LENGTH],
                self::settings('sitepolicies'),
                ['case' => 'length']
            );
        }
        if (empty($CFG->lockoutthreshold)) {
            $findings[] = $this->security_finding(
                'check:passwordstrength:messagelockout',
                [],
                self::settings('sitepolicies'),
                ['case' => 'lockout']
            );
        }
        return $findings;
    }
}
