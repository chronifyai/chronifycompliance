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
 * The standard log keeps every event for ever while the site logs many events a day, so the log table keeps
 * growing. The daily event counts come from the plugin's own activity data (data layer), not from the log table.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logretention extends site_check {
    /** @var int Days the average is taken over. */
    public const DAYS = 30;
    /** @var int Events a day from which a log that is never cleaned is reported. */
    public const MANY_EVENTS = 10000;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'logretention';
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
        if ((int) get_config('logstore_standard', 'loglifetime') > 0) {
            return [];
        }
        $readers = get_log_manager()->get_readers('\\core\\log\\sql_internal_table_reader');
        if (!isset($readers['logstore_standard'])) {
            return [];
        }
        $since = (int) date('Ymd', time() - self::DAYS * DAYSECS);
        $events = (int) $DB->get_field_sql(
            'SELECT SUM(events) FROM {local_chronifycompliance_siteday} WHERE day >= :since',
            ['since' => $since]
        );
        $perday = (int) round($events / self::DAYS);
        if ($perday < self::MANY_EVENTS) {
            return [];
        }
        return [$this->security_finding('check:logretention:message', ['perday' => $perday,
            'peryear' => $perday * 365], self::settings('logsettingstandard'))];
    }
}
