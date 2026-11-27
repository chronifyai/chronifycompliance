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

namespace local_completiondoctor\links;

use local_completiondoctor\task\check_links;

/**
 * Tests of the automatic link checks of new courses: off by default, picked by a scheduled task from
 * the course cache revision, never queued by events.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(link_scan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_completiondoctor\task\schedule_link_checks::class)]
final class link_auto_test extends \advanced_testcase {
    /**
     * Queued link check tasks.
     *
     * @return \stdClass[]
     */
    private function tasks(): array {
        global $DB;
        return $DB->get_records('task_adhoc', ['classname' => '\\' . check_links::class]);
    }

    /**
     * Run the queued link checks that are due.
     */
    private function run_checks(): void {
        ob_start();
        $this->runAdhocTasks(check_links::class);
        ob_end_clean();
    }

    /**
     * Prepare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Off by default: creating and editing courses queues nothing.
     */
    public function test_off_by_default(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertSame(0, link_scan::queue_changed_courses(time() + HOURSECS));
        $this->assertNull(link_scan::get_state((int) $course->id));
        $this->assertSame([], $this->tasks());
    }

    /**
     * A new course is checked once its content has been quiet for AUTO_DELAY, and again only after it changes.
     */
    public function test_changed_new_course(): void {
        global $DB;
        set_config('linkautonew', 1, 'local_completiondoctor');
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertSame([], $this->tasks(), 'Events queue nothing');
        $cacherev = (int) $DB->get_field('course', 'cacherev', ['id' => $course->id]);

        $this->assertSame(0, link_scan::queue_changed_courses($cacherev + 10), 'Still being edited');
        $this->assertSame(1, link_scan::queue_changed_courses($cacherev + link_scan::AUTO_DELAY));
        $state = link_scan::get_state((int) $course->id);
        $this->assertSame(link_scan::STATUS_QUEUED, $state->status);
        $this->assertSame(0, (int) $state->requestedby);
        $this->assertSame(0, link_scan::queue_changed_courses($cacherev + link_scan::AUTO_DELAY), 'Already queued');
        $this->run_checks();
        $this->assertSame(link_scan::STATUS_FINISHED, link_scan::get_state((int) $course->id)->status);
        $this->assertSame(0, link_scan::queue_changed_courses($cacherev + link_scan::AUTO_DELAY), 'Nothing changed');

        // A later change is picked up once quiet again.
        $DB->set_field('course', 'cacherev', $cacherev + 100, ['id' => $course->id]);
        $this->assertSame(1, link_scan::queue_changed_courses($cacherev + 100 + link_scan::AUTO_DELAY));
    }

    /**
     * A teacher's request starts a waiting automatic check now; old courses are never checked automatically.
     */
    public function test_request_and_old_courses(): void {
        global $DB;
        set_config('linkautonew', 1, 'local_completiondoctor');
        $course = $this->getDataGenerator()->create_course();
        $cacherev = (int) $DB->get_field('course', 'cacherev', ['id' => $course->id]);
        link_scan::queue_changed_courses($cacherev + link_scan::AUTO_DELAY);
        $teacher = $this->getDataGenerator()->create_user();
        $this->assertTrue(link_scan::queue((int) $course->id, (int) $teacher->id));
        $this->assertSame((int) $teacher->id, (int) link_scan::get_state((int) $course->id)->requestedby);
        $this->assertFalse(link_scan::queue((int) $course->id, (int) $teacher->id), 'Already requested');
        $this->assertCount(1, $this->tasks());

        $old = $this->getDataGenerator()->create_course();
        $DB->set_field('course', 'timecreated', time() - 31 * DAYSECS, ['id' => $old->id]);
        link_scan::queue_changed_courses(time() + HOURSECS);
        $this->assertNull(link_scan::get_state((int) $old->id));
    }
}
