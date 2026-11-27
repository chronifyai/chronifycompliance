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
 * The core completion tasks are disabled, failing, or have not run recently. Course completion is
 * aggregated by these tasks, so learners are not marked complete while they do not run.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completiontasks extends site_check {
    /** @var int Default maximum age of the last run of the regular task, in minutes. */
    public const DEFAULT_MAX_AGE = 60;
    /** @var int Maximum age of the last run of the daily task, in seconds. */
    private const DAILY_MAX_AGE = 26 * HOURSECS;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'completiontasks';
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
        global $CFG;
        if (empty($CFG->enablecompletion)) {
            return [];
        }
        $maxage = ((int) get_config('local_chronifycompliance', 'completiontaskmaxage') ?: self::DEFAULT_MAX_AGE) * MINSECS;
        $tasks = [
            '\\core\\task\\completion_regular_task' => $maxage,
            '\\core\\task\\completion_daily_task' => self::DAILY_MAX_AGE,
        ];
        $now = time();
        $findings = [];
        foreach ($tasks as $classname => $taskmaxage) {
            $task = \core\task\manager::get_scheduled_task($classname);
            if (!$task) {
                continue;
            }
            $identity = ['task' => $classname];
            $params = ['task' => $task->get_name(), 'classname' => $classname];
            $lastrun = (int) $task->get_last_run_time();
            if ($task->get_disabled()) {
                $findings[] = $this->site_finding('check:completiontasks:messagedisabled', $params, $identity);
            } else if (!$lastrun) {
                $findings[] = $this->site_finding(
                    'check:completiontasks:messagenever',
                    $params,
                    $identity,
                    self::SEVERITY_WARNING
                );
            } else if ($now - $lastrun > $taskmaxage) {
                $params['ago'] = format_time($now - $lastrun);
                $findings[] = $this->site_finding(
                    'check:completiontasks:messagestale',
                    $params,
                    $identity,
                    self::SEVERITY_WARNING
                );
            } else if ($task->get_fail_delay() > 0) {
                $findings[] = $this->site_finding(
                    'check:completiontasks:messagefailing',
                    $params,
                    $identity,
                    self::SEVERITY_WARNING
                );
            }
        }
        return $findings;
    }
}
