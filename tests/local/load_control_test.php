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

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\scorm\package_analyzer;
use local_chronifycompliance\scorm\runtime_evidence;
use local_chronifycompliance\task\analyze_queue;
use local_chronifycompliance\task\audit_course;

#[\PHPUnit\Framework\Attributes\CoversClass(analyze_queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(audit_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(runtime_evidence::class)]
/**
 * Tests of the production load safeguards: recorded criteria save time, package queue, scan pace,
 * pause switch and the stored SCORM evidence.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\task\analyze_queue
 * @covers \local_chronifycompliance\local\scan_manager
 * @covers \local_chronifycompliance\local\audit_store
 * @covers \local_chronifycompliance\scorm\runtime_evidence
 */
final class load_control_test extends \advanced_testcase {
    /**
     * Prepare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        check_registry::reset();
    }

    /**
     * Saving the completion settings is recorded by the observer and used without the log.
     */
    public function test_criteria_saved_recorded(): void {
        global $CFG;
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->assertNull(audit_store::get_criteria_saved((int) $course->id));
        \core\event\course_completion_updated::create(['courseid' => $course->id,
            'context' => \context_course::instance($course->id)])->trigger();
        $this->assertNotNull(audit_store::get_criteria_saved((int) $course->id));
        $this->assertNull(audit_store::get((int) $course->id), 'A recorded save is not an audit');

        // With no log at all, addedaftercriteria still works from the recorded time.
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        $generator = $this->getDataGenerator();
        $assign = $generator->create_module('assign', ['course' => $course->id, 'completion' => 2, 'completionsubmit' => 1]);
        $config = (object) ['id' => $course->id, 'criteria_activity' => [$assign->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);
        audit_store::set_criteria_saved((int) $course->id, time() - HOURSECS);
        $generator->create_module('quiz', ['course' => $course->id, 'completion' => 2, 'completionview' => 1]);
        $result = course_auditor::audit((int) $course->id, false, ['addedaftercriteria']);
        $this->assertCount(1, $result->findings);
    }

    /**
     * Big packages are queued during audits and analyzed by the queue task, which then re-audits.
     */
    public function test_package_queue(): void {
        global $DB;
        set_config('inlineanalysismb', 0, 'local_chronifycompliance');
        $course = (new \local_chronifycompliance\tests\demo\scenario\scormstage())->create(
            $this->getDataGenerator(),
            (int) $this->getDataGenerator()->create_category()->id
        );
        $result = course_auditor::audit((int) $course->id, true, ['scormstage']);
        $this->assertSame([], $result->findings, 'Not analyzed yet');
        $this->assertSame(1, package_analyzer::count_queued());

        set_config('paused', 1, 'local_chronifycompliance');
        $this->expectOutputRegex('/paused[\s\S]*Package [0-9a-f]{8}: ok/');
        (new analyze_queue())->execute();
        $this->assertSame(1, package_analyzer::count_queued(), 'Nothing runs while paused');

        set_config('paused', 0, 'local_chronifycompliance');
        (new analyze_queue())->execute();
        $this->assertSame(0, package_analyzer::count_queued());
        $this->assertTrue(
            $DB->record_exists('task_adhoc', ['classname' => '\\' . audit_course::class]),
            'Courses using the package are re-audited'
        );
        $result = course_auditor::audit((int) $course->id, true, ['scormstage']);
        $this->assertCount(1, $result->findings);
    }

    /**
     * Allowed hours ranges, including ranges over midnight.
     */
    public function test_allowed_hours(): void {
        $this->assertTrue(analyze_queue::within_allowed_hours('', 15));
        $this->assertTrue(analyze_queue::within_allowed_hours('0-6', 3));
        $this->assertFalse(analyze_queue::within_allowed_hours('0-6', 6));
        $this->assertTrue(analyze_queue::within_allowed_hours('22-5', 23));
        $this->assertTrue(analyze_queue::within_allowed_hours('22-5', 4));
        $this->assertFalse(analyze_queue::within_allowed_hours('22-5', 12));
    }

    /**
     * Scan batches start one pace apart; paused batches are postponed.
     */
    public function test_pace_and_pause(): void {
        global $DB;
        set_config('scanbatchsize', 1, 'local_chronifycompliance');
        set_config('scanpace', 60, 'local_chronifycompliance');
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_course();
        }
        $before = time();
        scan_manager::start_scan(0);
        $times = $DB->get_fieldset_select('task_adhoc', 'nextruntime', 'classname = ?', ['\\' . audit_course::class]);
        sort($times);
        $this->assertCount(3, $times);
        $this->assertGreaterThanOrEqual(60, $times[1] - $times[0]);
        $this->assertGreaterThanOrEqual(120, $times[2] - $times[0]);
        $this->assertGreaterThanOrEqual($before, $times[0]);

        set_config('paused', 1, 'local_chronifycompliance');
        $task = new audit_course();
        $task->set_custom_data(['courseids' => [SITEID + 1], 'scanid' => null, 'checkids' => null]);
        $this->expectOutputRegex('/paused/');
        $task->execute();
        $this->assertSame(4, $DB->count_records('task_adhoc', ['classname' => '\\' . audit_course::class]));
    }

    /**
     * Evidence is stored and reused for a day, collected again when refreshed or older.
     */
    public function test_evidence_stored(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $this->assertSame(0, runtime_evidence::get((int) $scorm->id)['attempts']);
        $DB->insert_record('scorm_attempt', ['userid' => 2, 'scormid' => $scorm->id, 'attempt' => 1]);
        $this->assertSame(0, runtime_evidence::get((int) $scorm->id)['attempts'], 'Stored');
        $this->assertSame(1, runtime_evidence::get((int) $scorm->id, null, true)['attempts'], 'Refreshed');

        $DB->insert_record('scorm_attempt', ['userid' => 2, 'scormid' => $scorm->id, 'attempt' => 2]);
        $DB->set_field(runtime_evidence::TABLE, 'timecollected', time() - DAYSECS - 1, ['scormid' => $scorm->id]);
        $readonly = runtime_evidence::get((int) $scorm->id, null, false, false);
        $this->assertSame(1, $readonly['attempts'], 'Page views read old evidence');
        $this->assertSame(2, runtime_evidence::get((int) $scorm->id)['attempts'], 'Checks collect old evidence again');
    }

    /**
     * Page views never collect: without stored evidence for the current package they get a pending result.
     */
    public function test_evidence_page_views_do_not_collect(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $DB->insert_record('scorm_attempt', ['userid' => 2, 'scormid' => $scorm->id, 'attempt' => 1]);

        $evidence = runtime_evidence::get((int) $scorm->id, null, false, false);
        $this->assertTrue($evidence['pending']);
        $this->assertSame(0, $evidence['attempts']);
        $this->assertFalse($DB->record_exists(runtime_evidence::TABLE, ['scormid' => $scorm->id]));

        $collected = runtime_evidence::get((int) $scorm->id);
        $this->assertFalse($collected['pending']);
        $this->assertSame(1, $collected['attempts']);
        $this->assertGreaterThan(0, $collected['timecollected']);
        $this->assertSame(1, runtime_evidence::get((int) $scorm->id, null, false, false)['attempts']);

        // A new package: stored evidence counted stale attempts against the old one.
        $this->assertTrue(runtime_evidence::get((int) $scorm->id, time(), false, false)['pending']);
    }

    /**
     * The evidence of deleted activities is removed.
     */
    public function test_evidence_orphans(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        runtime_evidence::get((int) $scorm->id);
        $DB->insert_record(runtime_evidence::TABLE, ['scormid' => -5, 'evidence' => '{}', 'timecollected' => time()]);
        $this->assertSame(1, runtime_evidence::delete_orphans());
        $this->assertTrue($DB->record_exists(runtime_evidence::TABLE, ['scormid' => $scorm->id]));
    }
}
