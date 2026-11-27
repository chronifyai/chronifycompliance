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

namespace local_completiondoctor;

use local_completiondoctor\check\course_auditor;
use local_completiondoctor\check\site_auditor;
use local_completiondoctor\tests\demo\seeder;

/**
 * Proves that audits never write to tables outside the plugin.
 *
 * Every non-plugin table is counted and checksummed (max id and max timemodified where present)
 * before and after auditing all demo courses and the site.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class readonly_audit_test extends \advanced_testcase {
    /**
     * Snapshot of every core and non-plugin table.
     *
     * @return array
     */
    private function snapshot(): array {
        global $DB;
        $snapshot = [];
        foreach ($DB->get_tables(false) as $table) {
            if (str_starts_with($table, 'local_completiondoctor_')) {
                continue;
            }
            $columns = $DB->get_columns($table, false);
            $select = 'COUNT(1) AS c';
            if (isset($columns['id'])) {
                $select .= ', MAX(id) AS maxid';
            }
            if (isset($columns['timemodified'])) {
                $select .= ', MAX(timemodified) AS maxmodified';
            }
            $snapshot[$table] = (array) $DB->get_record_sql("SELECT {$select} FROM {{$table}}");
        }
        return $snapshot;
    }

    /**
     * Saved audits of all demo courses and the site change no table outside the plugin.
     */
    public function test_audits_do_not_write_core_tables(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $courseids = seeder::seed();
        // Warm the course caches so that building them is not counted as a change.
        foreach ($courseids as $courseid) {
            get_fast_modinfo($courseid);
        }

        $before = $this->snapshot();
        site_auditor::audit(true);
        foreach ($courseids as $courseid) {
            course_auditor::audit($courseid, true);
        }
        $after = $this->snapshot();

        foreach ($before as $table => $values) {
            $this->assertSame($values, $after[$table], "Table {$table} changed during an audit");
        }
    }
}
