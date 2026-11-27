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
 * Completion tracking is disabled for the site while courses have completion set up, so none of it works.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completiondisabled extends site_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'completiondisabled';
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
        if (!empty($CFG->enablecompletion)) {
            return [];
        }
        $withcriteria = (int) $DB->count_records_sql('SELECT COUNT(DISTINCT course) FROM {course_completion_criteria}');
        $withtracking = $DB->count_records_select(
            'course',
            'id <> :siteid AND enablecompletion = 1',
            ['siteid' => SITEID]
        );
        if (!$withcriteria && !$withtracking) {
            return [];
        }
        return [$this->site_finding(
            'check:completiondisabled:message',
            ['criteria' => $withcriteria, 'tracking' => $withtracking]
        )];
    }
}
