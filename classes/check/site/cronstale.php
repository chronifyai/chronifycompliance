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

namespace local_completiondoctor\check\site;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\finding;
use local_completiondoctor\check\site_check;

/**
 * Cron is disabled or has not run recently, so completion is not updated.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cronstale extends site_check {
    /** @var int Default maximum minutes since the last cron start. */
    public const DEFAULT_MAX_AGE = 15;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'cronstale';
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
        $enabled = get_config('core', 'cron_enabled');
        if ($enabled !== false && !$enabled) {
            return [$this->site_finding('check:cronstale:messagedisabled')];
        }
        $laststart = (int) get_config('tool_task', 'lastcronstart');
        if (!$laststart) {
            return [$this->site_finding('check:cronstale:messagenever')];
        }
        $maxage = ((int) get_config('local_completiondoctor', 'cronmaxage') ?: self::DEFAULT_MAX_AGE) * MINSECS;
        $age = time() - $laststart;
        if ($age > $maxage) {
            return [$this->site_finding('check:cronstale:message', ['ago' => format_time($age)])];
        }
        return [];
    }
}
