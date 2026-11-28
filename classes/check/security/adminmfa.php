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
 * Site administrators can sign in with a password only: multi-factor authentication is off, or some
 * administrators have no second factor set up.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adminmfa extends security_check {
    /** @var string[] Factors a user sets up for themselves (a device, an app, a phone). */
    public const SETUP_FACTORS = ['totp', 'webauthn', 'sms'];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'adminmfa';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB;
        $admins = array_keys(get_admins());
        $url = new \moodle_url('/admin/category.php', ['category' => 'toolmfafolder']);
        if (!\core_component::get_component_directory('tool_mfa') || !get_config('tool_mfa', 'enabled')) {
            return [$this->security_finding('check:adminmfa:messageoff', ['count' => count($admins)], $url)];
        }
        // The email factor needs no set up: every administrator with an email address has it.
        if (get_config('factor_email', 'enabled')) {
            return [];
        }
        $factors = array_values(array_filter(self::SETUP_FACTORS, fn($f) => (bool) get_config('factor_' . $f, 'enabled')));
        $without = $admins;
        if ($factors && $admins) {
            [$userin, $params] = $DB->get_in_or_equal($admins, SQL_PARAMS_NAMED, 'u');
            [$factorin, $fparams] = $DB->get_in_or_equal($factors, SQL_PARAMS_NAMED, 'f');
            $with = $DB->get_fieldset_select(
                'tool_mfa',
                'DISTINCT userid',
                "userid {$userin} AND factor {$factorin} AND revoked = 0",
                $params + $fparams
            );
            $without = array_diff($admins, array_map('intval', $with));
        }
        if (!$without) {
            return [];
        }
        return [$this->security_finding('check:adminmfa:message', ['count' => count($without),
            'total' => count($admins)], $url)];
    }
}
