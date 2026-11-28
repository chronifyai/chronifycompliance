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
 * Web service tokens that never expire, and tokens that act as a site administrator. A leaked token works like a
 * password that is never changed.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wstokens extends security_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'wstokens';
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
        $noexpiry = $DB->count_records_select(
            'external_tokens',
            'tokentype = :permanent AND (validuntil IS NULL OR validuntil = 0)',
            ['permanent' => EXTERNAL_TOKEN_PERMANENT]
        );
        $url = new \moodle_url('/admin/webservice/tokens.php');
        if ($noexpiry) {
            $findings[] = $this->security_finding(
                'check:wstokens:messagenoexpiry',
                ['count' => $noexpiry],
                $url,
                ['case' => 'noexpiry']
            );
        }
        $admins = array_keys(get_admins());
        if ($admins) {
            [$insql, $params] = $DB->get_in_or_equal($admins, SQL_PARAMS_NAMED);
            $params['permanent'] = EXTERNAL_TOKEN_PERMANENT;
            $admintokens = $DB->count_records_select('external_tokens', "tokentype = :permanent AND userid {$insql}", $params);
            if ($admintokens) {
                $findings[] = $this->security_finding(
                    'check:wstokens:messageadmin',
                    ['count' => $admintokens],
                    $url,
                    ['case' => 'admin'],
                    self::SEVERITY_CRITICAL
                );
            }
        }
        return $findings;
    }
}
