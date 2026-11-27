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

namespace local_completiondoctor\local\demo;

use local_completiondoctor\check\course_auditor;
use local_completiondoctor\tests\demo\seeder;

/**
 * Demo seeder tests. Every scenario must trigger exactly its expected checks.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(seeder::class)]
final class seeder_test extends \advanced_testcase {
    /**
     * The seeder refuses to run on large sites.
     */
    public function test_refuses_large_sites(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < seeder::MAX_USERS; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/at most 100 users/');
        seeder::seed();
    }

    /**
     * Each scenario course triggers exactly the checks it expects, and seeding twice adds nothing.
     */
    public function test_scenarios_trigger_expected_checks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Log observers only run after commit on some versions (4.5): do not wrap this test in a transaction.
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $created = seeder::seed();
        $this->assertArrayHasKey('healthy', $created);
        $this->assertSame([], seeder::seed(), 'Seeding again creates nothing');

        $scenarios = seeder::get_scenarios();
        foreach ($created as $id => $courseid) {
            $result = course_auditor::audit($courseid, false);
            $this->assertSame([], $result->errors, "Scenario {$id}: check errors");
            $found = array_values(array_unique(array_map(fn($f) => $f->checkid, $result->findings)));
            sort($found);
            $expected = $scenarios[$id]->get_expected_checks();
            sort($expected);
            $this->assertSame($expected, $found, "Scenario {$id}: checks reporting findings");
        }

        $this->assertCount(count($scenarios), $created, 'Every scenario created a course');
        $this->assertGreaterThanOrEqual(count($created), seeder::purge());
    }
}
