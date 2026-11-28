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
 * Anyone can create an account: email based self registration is on. Without allowed email domains or
 * reCAPTCHA, bots can fill the site with accounts.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selfregistration extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'selfregistration';
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
        if (empty($CFG->registerauth) || !is_enabled_auth($CFG->registerauth)) {
            return [];
        }
        $domains = trim((string) ($CFG->allowemailaddresses ?? ''));
        $captcha = $CFG->registerauth === 'email' && get_config('auth_email', 'recaptcha');
        if ($domains !== '') {
            return [$this->security_finding(
                'check:selfregistration:messagedomains',
                ['domains' => $domains],
                self::settings('manageauths'),
                [],
                self::SEVERITY_INFO
            )];
        }
        return [$this->security_finding(
            $captcha ? 'check:selfregistration:messagecaptcha' : 'check:selfregistration:message',
            [],
            self::settings('manageauths'),
            [],
            $captcha ? self::SEVERITY_INFO : null
        )];
    }
}
