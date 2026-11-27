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

namespace local_chronifycompliance\review;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\task\cleanup;
use local_chronifycompliance\task\notify_teachers;

/**
 * Tests of the changes made after the senior review: new checks (enrolment, date criteria, activity
 * rules), softer unreachable rules, manual role capability, teacher notifications and clean-up.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\completion\enrolment
 * @covers \local_chronifycompliance\check\completion\datecriteria
 * @covers \local_chronifycompliance\check\completion\activityrules
 * @covers \local_chronifycompliance\check\completion\unreachable
 * @covers \local_chronifycompliance\check\completion\manualrole
 * @covers \local_chronifycompliance\check\completion\selfcompletionblock
 * @covers \local_chronifycompliance\task\notify_teachers
 * @covers \local_chronifycompliance\task\cleanup
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\enrolment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\datecriteria::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\activityrules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\unreachable::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\manualrole::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\completion\selfcompletionblock::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notify_teachers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup::class)]
final class review_changes_test extends \advanced_testcase {
    /**
     * Prepare.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        check_registry::reset();
    }

    /**
     * Findings of one check, without saving.
     *
     * @param int $courseid
     * @param string $checkid
     * @return finding[]
     */
    private function run_check(int $courseid, string $checkid): array {
        $result = course_auditor::audit($courseid, false, [$checkid]);
        $this->assertSame([], $result->errors);
        return $result->findings;
    }

    /**
     * A course with one required activity.
     *
     * @param string $modname
     * @param array $settings
     * @param array $course Course fields.
     * @return array [course, module]
     */
    private function course_with(string $modname, array $settings = [], array $course = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course($course + ['enablecompletion' => 1]);
        $module = $generator->create_module($modname, $settings + ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $config = (object) ['id' => $course->id, 'criteria_activity' => [$module->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);
        return [$course, $module];
    }

    /**
     * Enrolment: no enabled method, or every method closed; ended courses are skipped.
     */
    public function test_enrolment(): void {
        global $DB;
        [$course] = $this->course_with('page');
        $this->assertSame([], $this->run_check((int) $course->id, 'enrolment'), 'Manual enrolment is enabled');

        $DB->set_field('enrol', 'enrolenddate', time() - DAYSECS, ['courseid' => $course->id]);
        $findings = $this->run_check((int) $course->id, 'enrolment');
        $this->assertSame('check:enrolment:messageclosed', $findings[0]->messagekey);

        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $course->id]);
        $findings = $this->run_check((int) $course->id, 'enrolment');
        $this->assertSame('check:enrolment:messagenomethod', $findings[0]->messagekey);

        $DB->set_field('course', 'enddate', time() - DAYSECS, ['id' => $course->id]);
        $this->assertSame([], $this->run_check((int) $course->id, 'enrolment'), 'Course has ended');
    }

    /**
     * Self completion: a block shown on every course page from the site counts as the course's block.
     */
    public function test_selfcompletionblock_parent(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');
        [$course] = $this->course_with('page');
        (new \completion_criteria_self(['course' => $course->id]))->insert();
        $this->assertCount(1, $this->run_check((int) $course->id, 'selfcompletionblock'));

        $block = (object) ['blockname' => 'selfcompletion', 'parentcontextid' => \context_system::instance()->id,
            'showinsubcontexts' => 0, 'pagetypepattern' => 'course-view-*', 'defaultregion' => 'side-pre',
            'defaultweight' => 0, 'timecreated' => time(), 'timemodified' => time()];
        $block->id = $DB->insert_record('block_instances', $block);
        $this->assertCount(1, $this->run_check((int) $course->id, 'selfcompletionblock'), 'Not shown in subcontexts');

        $DB->set_field('block_instances', 'showinsubcontexts', 1, ['id' => $block->id]);
        $this->assertSame([], $this->run_check((int) $course->id, 'selfcompletionblock'));
    }

    /**
     * Duration longer than every enrolment period; completion date after the course end.
     */
    public function test_datecriteria(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_duration.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_date.php');
        [$course] = $this->course_with('page', [], ['enddate' => time() + 10 * DAYSECS]);
        (new \completion_criteria_duration(['course' => $course->id, 'enrolperiod' => 60 * DAYSECS]))->insert();
        $this->assertSame([], $this->run_check((int) $course->id, 'datecriteria'), 'Unlimited enrolment');

        $DB->set_field('enrol', 'enrolperiod', 30 * DAYSECS, ['courseid' => $course->id]);
        $this->assertSame([], $this->run_check((int) $course->id, 'datecriteria'), 'Manual period is only a default');

        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $course->id, 'enrol' => 'manual']);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $course->id, 'enrol' => 'self']);
        $findings = $this->run_check((int) $course->id, 'datecriteria');
        $this->assertSame('check:datecriteria:messageduration', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertStringContainsString('60 days', $findings[0]->get_message());

        $DB->set_field('enrol', 'enrolperiod', 0, ['courseid' => $course->id]);
        (new \completion_criteria_date(['course' => $course->id, 'timeend' => time() + 20 * DAYSECS]))->insert();
        $findings = $this->run_check((int) $course->id, 'datecriteria');
        $this->assertSame('check:datecriteria:messageafterend', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);
    }

    /**
     * Assignment without submissions, H5P without tracking, quiz with limited attempts.
     */
    public function test_activityrules(): void {
        global $DB;
        [$course, $assign] = $this->course_with('assign', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->assertSame([], $this->run_check((int) $course->id, 'activityrules'));
        $DB->set_field('assign', 'nosubmissions', 1, ['id' => $assign->id]);
        $findings = $this->run_check((int) $course->id, 'activityrules');
        $this->assertSame('check:activityrules:messageassignnosubmit', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity, 'Required');

        [$course, $h5p] = $this->course_with('h5pactivity', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'enabletracking' => 0]);
        $findings = $this->run_check((int) $course->id, 'activityrules');
        $this->assertSame('check:activityrules:messageh5pnotracking', $findings[0]->messagekey);

        [$course, $quiz] = $this->course_with('quiz', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1, 'attempts' => 2, 'gradepass' => 5]);
        $findings = $this->run_check((int) $course->id, 'activityrules');
        $this->assertSame('check:activityrules:messagequizattempts', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);
        $DB->set_field('quiz', 'completionattemptsexhausted', 1, ['id' => $quiz->id]);
        $this->assertSame([], $this->run_check((int) $course->id, 'activityrules'));
    }

    /**
     * Past "until" dates, empty groups and closed activities are warnings; ignored once the course ended.
     */
    public function test_unreachable_soft_reasons(): void {
        global $DB;
        [$course, $quiz] = $this->course_with('quiz', ['timeclose' => time() - DAYSECS]);
        $findings = $this->run_check((int) $course->id, 'unreachable');
        $this->assertCount(1, $findings);
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);
        $this->assertStringContainsString('closed on', $findings[0]->get_message());

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $tree = json_encode(['op' => '&', 'c' => [['type' => 'group', 'id' => (int) $group->id],
            ['type' => 'date', 'd' => '<', 't' => time() - DAYSECS]], 'showc' => [true, true]]);
        $DB->set_field('course_modules', 'availability', $tree, ['id' => $quiz->cmid]);
        rebuild_course_cache((int) $course->id, true);
        $findings = $this->run_check((int) $course->id, 'unreachable');
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity, 'Empty group and past date only');

        $DB->set_field('course', 'enddate', time() - HOURSECS, ['id' => $course->id]);
        $findings = $this->run_check((int) $course->id, 'unreachable');
        $this->assertStringNotContainsString('closed on', $findings[0]->get_message(), 'Dates ignored after the end');
        $this->assertStringContainsString('no members yet', $findings[0]->get_message());
    }

    /**
     * The manual completion role must be allowed to mark completion and held by an active user.
     */
    public function test_manualrole_capability_and_active(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_role.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $roleid = $generator->create_role(['shortname' => 'cdmarker', 'archetype' => '']);
        (new \completion_criteria_role(['course' => $course->id, 'role' => $roleid]))->insert();
        $user = $generator->create_user();
        role_assign($roleid, $user->id, \context_course::instance($course->id));

        $findings = $this->run_check((int) $course->id, 'manualrole');
        $this->assertSame('check:manualrole:messagenocap', $findings[0]->messagekey);

        assign_capability('moodle/course:markcomplete', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $this->assertSame([], $this->run_check((int) $course->id, 'manualrole'));

        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $findings = $this->run_check((int) $course->id, 'manualrole');
        $this->assertSame('check:manualrole:message', $findings[0]->messagekey);
        $this->assertStringContainsString('Test role', $findings[0]->get_message(), 'Role name shown');
    }

    /**
     * New critical findings notify teachers by popup only when the setting is on, and not on the first audit.
     */
    public function test_notify_teachers(): void {
        global $DB;
        \local_chronifycompliance\tests\role_grants::grant();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $tasks = fn() => $DB->count_records('task_adhoc', ['classname' => '\\' . notify_teachers::class]);

        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $this->assertSame(0, $tasks(), 'Off by default');

        set_config('notifyteachers', 1, 'local_chronifycompliance');
        $DB->delete_records(finding_store::TABLE);
        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $this->assertSame(1, $tasks());

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks(notify_teachers::class);
        ob_end_clean();
        $messages = array_filter($sink->get_messages(), fn($m) => (int) $m->useridto === (int) $teacher->id);
        $this->assertCount(1, $messages);
        $this->assertSame('criticalfinding', reset($messages)->eventtype);

        $fresh = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $fresh->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->assertNull(audit_store::get((int) $fresh->id));
        course_auditor::audit((int) $fresh->id, true, ['nocriteria']);
        $this->assertSame(0, $tasks(), 'Not on the first audit of a course');
    }

    /**
     * The clean-up task removes old resolved findings and rows of deleted courses, and keeps the rest.
     */
    public function test_cleanup(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        course_auditor::audit((int) $course->id, true, ['nocriteria']);
        $id = (int) $DB->get_field(finding_store::TABLE, 'id', ['courseid' => $course->id]);
        $DB->update_record(finding_store::TABLE, (object) ['id' => $id, 'status' => finding::STATUS_RESOLVED,
            'timemodified' => time() - (cleanup::KEEP_DAYS + 1) * DAYSECS]);
        $DB->insert_record(finding_store::TABLE, ['courseid' => 999999, 'checkid' => 'x', 'component' => 'local_chronifycompliance',
            'severity' => 1, 'baseseverity' => 1, 'fingerprint' => sha1('gone'), 'status' => 'open', 'messagekey' => 'x',
            'fixable' => 0, 'timecreated' => time(), 'timemodified' => time(), 'lastseen' => time()]);

        $this->expectOutputRegex('/cleanup/');
        (new cleanup())->execute();
        $this->assertSame(0, $DB->count_records(finding_store::TABLE));
        $this->assertTrue($DB->record_exists('local_chronifycompliance_audit', ['courseid' => $course->id]));
    }
}
