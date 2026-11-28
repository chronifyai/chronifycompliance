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
 * The roles everyone gets (authenticated user, guest, and the default front page role) are allowed
 * capabilities that can change the site configuration, lose data or run scripts (XSS), across the site,
 * beyond what Moodle gives these roles by default.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class riskyroles extends security_check {
    /** @var int Risks that should never be given to everyone. */
    public const RISKS = RISK_CONFIG | RISK_DATALOSS | RISK_XSS;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'riskyroles';
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
        global $CFG, $DB;
        $roleids = array_unique(array_filter([
            (int) ($CFG->defaultuserroleid ?? 0),
            (int) ($CFG->guestroleid ?? 0),
            (int) ($CFG->defaultfrontpageroleid ?? 0),
        ]));
        if (!$roleids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params += ['contextid' => \context_system::instance()->id, 'allow' => CAP_ALLOW];
        $risk = $DB->sql_bitand('c.riskbitmask', self::RISKS);
        $records = $DB->get_records_sql(
            "SELECT rc.id, rc.roleid, rc.capability
               FROM {role_capabilities} rc
               JOIN {capabilities} c ON c.name = rc.capability
              WHERE rc.roleid {$insql} AND rc.contextid = :contextid AND rc.permission = :allow AND {$risk} <> 0",
            $params
        );
        $roles = role_fix_names($DB->get_records_list('role', 'id', $roleids));
        // Capabilities Moodle gives the role's archetype by default (for example "request data deletion" for
        // authenticated users) are expected; only the ones added on top are reported.
        $defaults = [];
        foreach ($roles as $roleid => $role) {
            $defaults[$roleid] = $role->archetype ? get_default_capabilities($role->archetype) : [];
        }
        $counts = [];
        foreach ($records as $record) {
            if (($defaults[$record->roleid][$record->capability] ?? null) === CAP_ALLOW) {
                continue;
            }
            $counts[$record->roleid] = ($counts[$record->roleid] ?? 0) + 1;
        }
        $findings = [];
        foreach ($counts as $roleid => $count) {
            $findings[] = $this->security_finding(
                'check:riskyroles:message',
                ['role' => $roles[$roleid]->localname ?? (string) $roleid, 'count' => $count],
                new \moodle_url('/admin/roles/define.php', ['action' => 'view', 'roleid' => $roleid]),
                ['roleid' => (int) $roleid]
            );
        }
        return $findings;
    }
}
