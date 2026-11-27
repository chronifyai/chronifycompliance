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

namespace local_completiondoctor\local;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\tests\check_testcase_trait;
use local_completiondoctor\tests\fake_check;

/**
 * Tests for queued scans, the audit task and the event observers.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_completiondoctor\task\audit_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_completiondoctor\task\scan_site::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_completiondoctor\observer::class)]
final class scan_manager_test extends \advanced_testcase {
    use check_testcase_trait;

    /**
     * Register a fake check reporting one finding per course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->register_checks([new fake_check()]);
        fake_check::$runner = fn(audit_context $c, fake_check $check) => [$check->emit((int) $c->course->id)];
    }

    /**
     * Reset the registry.
     */
    protected function tearDown(): void {
        $this->reset_checks();
        parent::tearDown();
    }

    /**
     * Number of queued audit tasks.
     *
     * @return int
     */
    private function count_tasks(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => '\local_completiondoctor\task\audit_course']);
    }

    /**
     * A scan queues batches, the tasks audit every course and the scan finishes.
     */
    public function test_scan_in_batches(): void {
        global $DB;
        set_config('scanbatchsize', 2, 'local_completiondoctor');
        $courses = [];
        for ($i = 0; $i < 5; $i++) {
            $courses[] = $this->getDataGenerator()->create_course();
        }

        $scanid = scan_manager::start_scan(3, null, null, ['fakecheck']);
        $scan = scan_manager::get_scan($scanid);
        $this->assertEquals(5, $scan->totalitems);
        $this->assertSame(scan_manager::STATUS_QUEUED, $scan->status);
        $this->assertSame(3, $this->count_tasks());

        $this->expectOutputRegex('/Course \d+: 1 findings/');
        $this->runAdhocTasks('\local_completiondoctor\task\audit_course');

        $scan = scan_manager::get_scan($scanid);
        $this->assertSame(scan_manager::STATUS_FINISHED, $scan->status);
        $this->assertEquals(5, $scan->processed);
        $this->assertEquals(0, $scan->failed);
        $this->assertNotEmpty($scan->timefinished);
        foreach ($courses as $course) {
            $this->assertSame(1, $DB->count_records(finding_store::TABLE, ['courseid' => $course->id]));
        }
    }

    /**
     * Category scans include subcategories only; limits apply.
     */
    public function test_category_and_limit(): void {
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $other = $generator->create_category();
        $generator->create_course(['category' => $parent->id]);
        $generator->create_course(['category' => $child->id]);
        $generator->create_course(['category' => $other->id]);

        $this->assertEquals(2, scan_manager::get_scan(scan_manager::start_scan(0, $parent->id))->totalitems);
        $this->assertEquals(1, scan_manager::get_scan(scan_manager::start_scan(0, $child->id))->totalitems);
        $this->assertEquals(1, scan_manager::get_scan(scan_manager::start_scan(0, null, 1))->totalitems);
    }

    /**
     * A cancelled scan skips its remaining batches.
     */
    public function test_cancel(): void {
        global $DB;
        $this->getDataGenerator()->create_course();
        $scanid = scan_manager::start_scan(0);
        scan_manager::cancel($scanid);
        $this->assertSame(scan_manager::STATUS_CANCELLED, scan_manager::get_scan($scanid)->status);
        $this->expectOutputRegex('/was cancelled/');
        $this->runAdhocTasks('\\local_completiondoctor\\task\\audit_course');
        $this->assertSame(0, $DB->count_records(finding_store::TABLE));
    }

    /**
     * Course events queue one delayed re-audit only when the setting is on.
     */
    public function test_observer_respects_setting_and_deduplicates(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertSame(0, $this->count_tasks());

        set_config('autoreaudit', 1, 'local_completiondoctor');
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertSame(1, $this->count_tasks());
    }

    /**
     * The scheduled task runs site checks, queues a scan and does not start a second one.
     */
    public function test_scheduled_task(): void {
        $this->getDataGenerator()->create_course();
        $task = new \local_completiondoctor\task\scan_site();
        $this->assertTrue((bool) \core\task\manager::get_scheduled_task(\local_completiondoctor\task\scan_site::class)
            ->get_disabled(), 'The scheduled scan must ship disabled');

        ob_start();
        $task->execute();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('still in progress', $output);
        $this->assertSame(1, $this->count_tasks());
    }
}
