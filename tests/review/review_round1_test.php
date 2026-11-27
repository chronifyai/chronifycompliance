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

namespace local_chronifycompliance;

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\links\link_checker;
use local_chronifycompliance\links\link_scan;
use local_chronifycompliance\links\link_store;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\scorm\package_analyzer;
use local_chronifycompliance\task\audit_course;
use local_chronifycompliance\task\check_course;
use local_chronifycompliance\task\cleanup;

#[\PHPUnit\Framework\Attributes\CoversClass(check_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(audit_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(audit_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(package_analyzer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(link_scan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(link_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(finding_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\circular::class)]
/**
 * Tests for the fixes of code review rounds 1 and 2: background requests, paused and cancelled scans,
 * package locks, dead link checks, bulk timestamps and the per-audit prerequisite cache.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\task\check_course
 * @covers \local_chronifycompliance\local\audit_store
 * @covers \local_chronifycompliance\local\scan_manager
 * @covers \local_chronifycompliance\task\audit_course
 * @covers \local_chronifycompliance\scorm\package_analyzer
 * @covers \local_chronifycompliance\links\link_scan
 * @covers \local_chronifycompliance\links\link_store
 * @covers \local_chronifycompliance\local\finding_store
 * @covers \local_chronifycompliance\check\completion\circular
 */
final class review_round1_test extends \advanced_testcase {
    /**
     * Reset after each test.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
    }

    /**
     * A course with completion and one tracked activity, so the nocriteria check finds something.
     *
     * @return \stdClass
     */
    private function course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        return $course;
    }

    /**
     * "Check again" while the same check runs queues a new check; a check answers only earlier requests.
     */
    public function test_request_during_running_check(): void {
        global $DB;
        $course = $this->course();
        $tasks = fn() => $DB->count_records('task_adhoc', ['classname' => '\\' . check_course::class]);

        check_course::request((int) $course->id);
        check_course::request((int) $course->id);
        $this->assertSame(1, $tasks(), 'A waiting check is not duplicated');

        $DB->set_field('task_adhoc', 'timestarted', time(), ['classname' => '\\' . check_course::class]);
        check_course::request((int) $course->id);
        $this->assertSame(2, $tasks(), 'A running check does not absorb the request');

        $DB->set_field(audit_store::TABLE, 'timerequested', time() + 5, ['courseid' => $course->id]);
        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $this->assertGreaterThan(0, audit_store::get_requested((int) $course->id), 'A later request stays open');

        $DB->set_field(audit_store::TABLE, 'timerequested', time() - 5, ['courseid' => $course->id]);
        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $this->assertSame(0, audit_store::get_requested((int) $course->id), 'An earlier request is answered');

        // Review round 2: a request made in the second the check started is answered too.
        $result = course_auditor::audit((int) $course->id, false, ['nocriteria']);
        $DB->set_field(audit_store::TABLE, 'timerequested', $result->timestarted, ['courseid' => $course->id]);
        audit_store::record($result);
        $this->assertSame(0, audit_store::get_requested((int) $course->id), 'A request in the same second is answered');
    }

    /**
     * The clean-up task forgets requests that were never answered, and fails dead link checks.
     */
    public function test_cleanup_expired_requests_and_dead_link_checks(): void {
        global $DB;
        $course = $this->course();
        audit_store::request((int) $course->id);
        $DB->set_field(audit_store::TABLE, 'timerequested', time() - check_course::REQUEST_EXPIRY - 1, ['courseid' => $course->id]);
        $fresh = $this->course();
        audit_store::request((int) $fresh->id);

        $dead = $DB->insert_record('local_chronifycompliance_linkcheck', ['courseid' => $course->id, 'status' => 'running',
            'timecreated' => time(), 'timemodified' => time() - link_scan::STUCK_AFTER - 1]);
        $alive = $DB->insert_record('local_chronifycompliance_linkcheck', ['courseid' => $fresh->id, 'status' => 'running',
            'timecreated' => time(), 'timemodified' => time()]);

        $this->expectOutputRegex('/cleanup/');
        (new cleanup())->execute();
        $this->assertSame(0, audit_store::get_requested((int) $course->id));
        $this->assertGreaterThan(0, audit_store::get_requested((int) $fresh->id));
        $this->assertSame(
            link_scan::STATUS_FAILED,
            $DB->get_field('local_chronifycompliance_linkcheck', 'status', ['id' => $dead])
        );
        $this->assertSame(
            link_scan::STATUS_RUNNING,
            $DB->get_field('local_chronifycompliance_linkcheck', 'status', ['id' => $alive])
        );
    }

    /**
     * A dead link check no longer blocks a new request for its course.
     */
    public function test_dead_link_check_does_not_block(): void {
        global $DB;
        $course = $this->course();
        $DB->insert_record('local_chronifycompliance_linkcheck', ['courseid' => $course->id, 'status' => 'running',
            'timecreated' => time(), 'timemodified' => time() - link_scan::STUCK_AFTER - 1]);
        $this->assertTrue(link_scan::queue((int) $course->id, 2));
        $this->assertSame(link_scan::STATUS_QUEUED, link_scan::get_state((int) $course->id)->status);
    }

    /**
     * A long pause does not make a scan look stuck, and reading the active scan never cancels it.
     */
    public function test_paused_scan_survives(): void {
        global $DB;
        $this->course();
        $scanid = scan_manager::start_scan(0, null, null, ['nocriteria']);
        $DB->set_field(scan_manager::TABLE, 'timemodified', time() - scan_manager::STUCK_AFTER - 1, ['id' => $scanid]);

        set_config('paused', 1, 'local_chronifycompliance');
        $this->expectOutputRegex('/batch postponed/');
        $this->runAdhocTasks(audit_course::class);
        $this->assertGreaterThan(time() - 60, (int) $DB->get_field(scan_manager::TABLE, 'timemodified', ['id' => $scanid]));

        set_config('paused', 0, 'local_chronifycompliance');
        $this->assertSame((int) $scanid, (int) scan_manager::get_active_scan()->id);
        $this->assertSame(0, scan_manager::cancel_stuck());

        $DB->set_field(scan_manager::TABLE, 'timemodified', time() - scan_manager::STUCK_AFTER - 1, ['id' => $scanid]);
        $this->assertNotNull(scan_manager::get_active_scan(), 'Reading does not cancel');
        $this->assertSame(1, scan_manager::cancel_stuck());
    }

    /**
     * Cancelling leaves Moodle's task queue alone; the waiting batch skips itself.
     */
    public function test_cancel_keeps_task_queue(): void {
        global $DB;
        $course = $this->course();
        $scanid = scan_manager::start_scan(0, null, null, ['nocriteria']);
        scan_manager::cancel($scanid);
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\' . audit_course::class]));

        $this->expectOutputRegex('/was cancelled/');
        $this->runAdhocTasks(audit_course::class);
        $this->assertSame(0, $DB->count_records(finding_store::TABLE, ['courseid' => $course->id]));
        $this->assertSame(scan_manager::STATUS_CANCELLED, scan_manager::get_scan($scanid)->status);
    }

    /**
     * Review round 2: while scans are paused, a batch of a cancelled scan ends instead of queuing itself again.
     */
    public function test_cancelled_scan_while_paused(): void {
        global $DB;
        $this->course();
        set_config('paused', 1, 'local_chronifycompliance');
        $scanid = scan_manager::start_scan(0, null, null, ['nocriteria']);
        scan_manager::cancel($scanid);

        $this->expectOutputRegex('/was cancelled/');
        $this->runAdhocTasks(audit_course::class);
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . audit_course::class]));
    }

    /**
     * A package being analyzed by another process is reported as pending, not read again.
     */
    public function test_package_lock(): void {
        global $CFG;
        // Database locks are re-entrant within one connection; file locks are not, so the test can hold one.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id, 'component' => 'local_chronifycompliance', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'p.zip',
        ], __DIR__ . '/../fixtures/packages/storyline_fake.zip');
        $factory = \core\lock\lock_config::get_lock_factory('local_chronifycompliance');
        $lock = $factory->get_lock('package_' . $file->get_contenthash(), 0);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame(package_analyzer::STATUS_PENDING, package_analyzer::analyze_stored_file($file)['status']);
        } finally {
            $lock->release();
        }
        $this->assertSame(package_analyzer::STATUS_OK, package_analyzer::analyze_stored_file($file)['status']);
    }

    /**
     * Bulk changes of findings and stored links set the persistent timestamps.
     */
    public function test_bulk_writes_set_timestamps(): void {
        global $DB;
        $course = $this->course();
        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $DB->set_field(finding_store::TABLE, 'timemodified', 1000, ['courseid' => $course->id]);

        set_config('enabled_nocriteria', 0, 'local_chronifycompliance');
        \local_chronifycompliance\check\check_registry::reset();
        finding_store::apply_enabled_setting('nocriteria');
        $finding = $DB->get_record(finding_store::TABLE, ['courseid' => $course->id]);
        $this->assertSame(finding::STATUS_RESOLVED, $finding->status);
        $this->assertGreaterThan(time() - 60, (int) $finding->timemodified);

        $url = 'https://example.com/';
        link_store::replace_course((int) $course->id, [['cmid' => 0, 'source' => 'summary', 'itemid' => 0,
            'sourcename' => 'x', 'url' => $url, 'linktext' => 'x', 'urlhash' => sha1($url), 'kind' => link_checker::KIND_EXTERNAL,
            'status' => link_checker::STATUS_OK, 'httpcode' => 200, 'reason' => '', 'timechecked' => time()]]);
        $this->assertGreaterThan(0, (int) $DB->get_field(link_store::TABLE, 'timecreated', ['courseid' => $course->id]));
    }

    /**
     * A removed course prerequisite is seen by the next check in the same process.
     */
    public function test_circular_not_cached_between_audits(): void {
        global $DB;
        $a = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $b = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $DB->insert_record('course_completion_criteria', ['course' => $a->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE,
            'courseinstance' => $b->id]);
        $back = $DB->insert_record('course_completion_criteria', ['course' => $b->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseinstance' => $a->id]);
        $cycles = fn() => array_filter(
            course_auditor::audit((int) $a->id, false, ['circular'])->findings,
            fn(finding $f) => $f->messagekey === 'check:circular:messagecourses'
        );
        $this->assertCount(1, $cycles());

        $DB->delete_records('course_completion_criteria', ['id' => $back]);
        $this->assertCount(0, $cycles());
    }
}
