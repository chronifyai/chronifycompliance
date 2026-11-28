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
 * Site administrator accounts that have not been used for a long time. Unused accounts with full rights are a
 * common way in: their passwords are old and nobody notices a sign in.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inactiveadmins extends security_check {
    /** @var int Days without a sign in after which an administrator counts as inactive. */
    public const DAYS = 90;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'inactiveadmins';
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
        $before = time() - self::DAYS * DAYSECS;
        $inactive = 0;
        foreach (get_admins() as $admin) {
            $last = max((int) $admin->lastaccess, (int) $admin->lastlogin, (int) $admin->currentlogin);
            if (!$admin->suspended && $last < $before) {
                $inactive++;
            }
        }
        if (!$inactive) {
            return [];
        }
        return [$this->security_finding(
            'check:inactiveadmins:message',
            ['count' => $inactive, 'days' => self::DAYS],
            new \moodle_url('/admin/roles/admins.php')
        )];
    }
}
