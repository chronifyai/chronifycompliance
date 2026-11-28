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

namespace local_chronifycompliance\check\site;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_check;

/**
 * Scheduled and ad hoc tasks that keep failing (Moodle retries them with a growing delay), and scheduled tasks
 * that were turned off. Reads Moodle's task tables, which are small.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failingtasks extends site_check {
    /** @var string[] Tasks covered by the completiontasks check. */
    public const OWN_CHECK = ['\\core\\task\\completion_regular_task', '\\core\\task\\completion_daily_task'];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'failingtasks';
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
        // The completion tasks have their own check (completiontasks).
        [$notin, $params] = $DB->get_in_or_equal(self::OWN_CHECK, SQL_PARAMS_NAMED, 'c', false);
        $scheduled = $DB->count_records_select('task_scheduled', "faildelay > 0 AND disabled = 0 AND classname {$notin}", $params);
        if ($scheduled) {
            $findings[] = $this->security_finding(
                'check:failingtasks:messagescheduled',
                ['count' => $scheduled],
                new \moodle_url('/admin/tool/task/scheduledtasks.php'),
                ['case' => 'scheduled']
            );
        }
        $adhoc = $DB->count_records_select('task_adhoc', 'faildelay > 0');
        if ($adhoc) {
            $findings[] = $this->security_finding(
                'check:failingtasks:messageadhoc',
                ['count' => $adhoc],
                new \moodle_url('/admin/tool/task/adhoctasks.php'),
                ['case' => 'adhoc']
            );
        }
        return $findings;
    }
}
