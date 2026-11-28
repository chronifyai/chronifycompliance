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

namespace local_chronifycompliance\local;

use core_privacy\local\request\approved_contextlist;
use local_chronifycompliance\privacy\provider;
use local_chronifycompliance\task\cleanup;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup::class)]
/**
 * Tests for the tables reserved for later releases (Connect and signed fix plans): they exist, start empty,
 * and are covered by privacy, course deletion and cleanup.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\privacy\provider
 * @covers \local_chronifycompliance\task\cleanup
 */
final class reserved_tables_test extends \advanced_testcase {
    /**
     * Add a fix plan row as a later release would.
     *
     * @param int $courseid
     * @param int $appliedby
     */
    private function add_plan(int $courseid, int $appliedby = 0): void {
        global $DB;
        $DB->insert_record('local_chronifycompliance_fixplan', [
            'courseid' => $courseid, 'planid' => random_string(20), 'plan' => '{}', 'signature' => 'x',
            'appliedby' => $appliedby ?: null, 'usermodified' => $appliedby, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * The tables exist and nothing writes to them in this release.
     */
    public function test_tables_exist_and_are_empty(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_chronifycompliance_connect', 'local_chronifycompliance_fixplan'] as $table) {
            $this->assertTrue($dbman->table_exists($table), $table);
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * Fix plans go with their course and are cleaned up when the course is gone.
     */
    public function test_fix_plans_follow_courses(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->add_plan($course->id);
        $this->add_plan(SITEID);
        delete_course($course, false);
        $this->assertFalse($DB->record_exists('local_chronifycompliance_fixplan', ['courseid' => $course->id]));

        $this->add_plan(999999);
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertFalse($DB->record_exists('local_chronifycompliance_fixplan', ['courseid' => 999999]));
        $this->assertTrue($DB->record_exists('local_chronifycompliance_fixplan', ['courseid' => SITEID]), 'Site plans stay');
    }

    /**
     * Privacy: the administrator who applied a plan or gave consent is found and anonymized.
     */
    public function test_privacy(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->add_plan($course->id, $admin->id);
        $DB->insert_record('local_chronifycompliance_connect', ['status' => 'connected', 'usermodified' => $admin->id]);

        $contexts = provider::get_contexts_for_userid($admin->id)->get_contextids();
        $coursecontext = \context_course::instance($course->id);
        $this->assertContainsEquals($coursecontext->id, $contexts);
        $this->assertContainsEquals(\context_system::instance()->id, $contexts);

        provider::delete_data_for_user(new approved_contextlist(
            $admin,
            'local_chronifycompliance',
            [$coursecontext->id, \context_system::instance()->id]
        ));
        $this->assertFalse($DB->record_exists('local_chronifycompliance_fixplan', ['appliedby' => $admin->id]));
        $this->assertFalse($DB->record_exists('local_chronifycompliance_connect', ['usermodified' => $admin->id]));
    }
}
