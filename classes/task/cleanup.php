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

namespace local_completiondoctor\task;

use local_completiondoctor\links\link_scan;
use local_completiondoctor\local\audit_store;
use local_completiondoctor\local\scan_manager;
use local_completiondoctor\scorm\package_analyzer;

/**
 * Hourly cleanup of the plugin's own tables: stuck and old scans, dead and old link checks, check
 * requests that were never answered, resolved findings, analyses of packages that no longer exist or
 * of older parser versions, and rows of deleted courses.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var int Days finished scans, link checks and resolved findings are kept. */
    public const KEEP_DAYS = 90;
    /** @var int Days an analysis of a package file that is gone is kept (the file may come back from the recycle bin). */
    public const KEEP_ORPHAN_DAYS = 7;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanup', 'local_completiondoctor');
    }

    /**
     * Clean up.
     */
    public function execute(): void {
        $now = time();
        $old = $now - self::KEEP_DAYS * DAYSECS;
        $counts = [];

        $counts['stuckscans'] = scan_manager::cancel_stuck($now);
        $counts['stucklinkchecks'] = link_scan::fail_stuck($now);
        $counts['requests'] = audit_store::clear_expired_requests($now - check_course::REQUEST_EXPIRY);
        $counts['scans'] = $this->delete(
            'local_completiondoctor_scan',
            "status IN ('finished', 'cancelled') AND timemodified < :old",
            ['old' => $old]
        );

        // Link checks: keep the latest of each course.
        $counts['linkchecks'] = $this->delete(
            'local_completiondoctor_linkcheck',
            "status IN ('finished', 'failed', 'cancelled') AND timemodified < :old
             AND id NOT IN (SELECT latest FROM (SELECT MAX(id) AS latest FROM {local_completiondoctor_linkcheck}
                                                 GROUP BY courseid) keep)",
            ['old' => $old]
        );

        // Resolved findings; fixed and ignored ones stay (undo and the ignore reason need them).
        $counts['findings'] = $this->delete(
            'local_completiondoctor_finding',
            "status = 'resolved' AND timemodified < :old",
            ['old' => $old]
        );

        // Analyses of other parser versions, and of package files that no longer exist.
        $counts['packages'] = $this->delete(
            package_analyzer::TABLE,
            'parserversion <> :version',
            ['version' => package_analyzer::PARSER_VERSION]
        );
        $counts['packages'] += $this->delete_orphan_packages($now - self::KEEP_ORPHAN_DAYS * DAYSECS);

        // Rows of deleted courses (normally removed by the course_deleted observer).
        foreach (
            ['local_completiondoctor_finding', 'local_completiondoctor_fixlog', 'local_completiondoctor_audit',
                'local_completiondoctor_link', 'local_completiondoctor_linkcheck'] as $table
        ) {
            $counts[$table] = $this->delete(
                $table,
                "courseid <> :siteid AND NOT EXISTS (SELECT 1 FROM {course} c WHERE c.id = {{$table}}.courseid)",
                ['siteid' => SITEID]
            );
        }
        $counts = array_filter($counts);
        if ($counts) {
            \cache::make('local_completiondoctor', 'coursesummary')->purge();
            mtrace('Completion Doctor cleanup: ' . json_encode($counts));
        }
    }

    /**
     * Delete analyses of package files that no longer exist. Checked hash by hash (the table is small):
     * a join on the hash fails on sites whose core and plugin tables have different collations.
     *
     * @param int $before Only analyses not changed since then.
     * @return int Rows deleted.
     */
    private function delete_orphan_packages(int $before): int {
        global $DB;
        $deleted = 0;
        $rows = $DB->get_records_select_menu(
            package_analyzer::TABLE,
            'timemodified < :before',
            ['before' => $before],
            '',
            'id, contenthash'
        );
        foreach ($rows as $id => $hash) {
            if (!$DB->record_exists('files', ['contenthash' => $hash])) {
                $DB->delete_records(package_analyzer::TABLE, ['id' => $id]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Delete rows of a table and count them.
     *
     * @param string $table
     * @param string $select
     * @param array $params
     * @return int Rows deleted.
     */
    private function delete(string $table, string $select, array $params): int {
        global $DB;
        $count = $DB->count_records_select($table, $select, $params);
        if ($count) {
            $DB->delete_records_select($table, $select, $params);
        }
        return $count;
    }
}
