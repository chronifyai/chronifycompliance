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

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\external\explain_user_completion;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\hook_callbacks;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\privacy\provider;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Probes from the senior review (2026-10-28): each test asserts the behavior the plugin should have;
 * a failure reproduces a review finding. test_requireddeleted_fix_unblocks_learner follows decision D15:
 * fixes never recalculate completion, as Moodle's own completion settings page does not either.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class review_probe_test extends \advanced_testcase {
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
     * A course with one automatic-completion SCORM activity.
     *
     * @param array $settings
     * @param string $fixture
     * @return array [course, scorm with cmid]
     */
    private function scorm(array $settings, string $fixture = 'storyline_fake'): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $path = \core_component::get_component_directory('local_chronifycompliance') . "/tests/fixtures/packages/{$fixture}.zip";
        $scorm = $generator->create_module('scorm', $settings + ['course' => $course->id, 'packagefilepath' => $path,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionstatusrequired' => 4]);
        return [$course, $scorm];
    }

    /**
     * Change SCORM and course module fields directly.
     *
     * @param \stdClass $scorm
     * @param array $fields
     * @param array $cmfields
     */
    private function set_scorm(\stdClass $scorm, array $fields, array $cmfields = []): void {
        global $DB;
        $DB->update_record('scorm', (object) (['id' => $scorm->id] + $fields));
        if ($cmfields) {
            $DB->update_record('course_modules', (object) (['id' => $scorm->cmid] + $cmfields));
        }
        rebuild_course_cache((int) $scorm->course, true);
    }

    /**
     * Learner attempts with the given tracking values.
     *
     * @param \stdClass $scorm
     * @param array $values Element => value.
     * @param int $count
     */
    private function attempts(\stdClass $scorm, array $values, int $count = 5): void {
        global $DB;
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco'], IGNORE_MULTIPLE);
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $attemptid = $DB->insert_record('scorm_attempt', ['userid' => $user->id, 'scormid' => $scorm->id, 'attempt' => 1]);
            foreach ($values as $name => $value) {
                $elementid = $DB->get_field('scorm_element', 'id', ['element' => $name])
                    ?: $DB->insert_record('scorm_element', ['element' => $name]);
                $DB->insert_record('scorm_scoes_value', ['scoid' => $scoid, 'attemptid' => $attemptid,
                    'elementid' => $elementid, 'value' => (string) $value, 'timemodified' => time()]);
            }
        }
        \local_chronifycompliance\scorm\runtime_evidence::purge();
    }

    /**
     * Require activities for course completion.
     *
     * @param int $courseid
     * @param int[] $cmids
     */
    private function require_cms(int $courseid, array $cmids): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        require_once($CFG->dirroot . '/completion/completion_aggregation.php');
        $data = (object) ['id' => $courseid, 'criteria_activity' => array_fill_keys($cmids, 1)];
        (new \completion_criteria_activity())->update_config($data);
        if (!$DB->record_exists('course_completion_aggr_methd', ['course' => $courseid, 'criteriatype' => null])) {
            (new \completion_aggregation(['course' => $courseid, 'criteriatype' => null,
                'method' => COMPLETION_AGGREGATION_ALL]))->save();
        }
    }

    /**
     * Stored open findings of one check after a saved audit.
     *
     * @param int $courseid
     * @param string $checkid
     * @return finding[]
     */
    private function findings(int $courseid, string $checkid): array {
        $result = course_auditor::audit($courseid, true, [$checkid]);
        $this->assertSame([], $result->errors);
        return array_values(array_filter(finding_store::get_course_findings($courseid), fn($f) => $f->checkid === $checkid));
    }

    /**
     * Findings of one check as readable lines.
     *
     * @param int $courseid
     * @param string $checkid
     * @return string[]
     */
    private function lines(int $courseid, string $checkid): array {
        return array_map(fn(finding $f) => $f->messagekey . ' severity ' . $f->severity . ' recommended '
            . json_encode($f->details['recommended'] ?? null), $this->findings($courseid, $checkid));
    }

    /**
     * Run the core completion task.
     */
    private function run_completion_cron(): void {
        $this->waitForSecond();
        ob_start();
        (new \core\task\completion_regular_task())->execute();
        ob_end_clean();
    }

    /**
     * The teacher banner appears on the course page of a course with open critical findings.
     */
    public function test_banner_shows_on_course_page(): void {
        global $PAGE, $DB;
        set_config('showbanner', 1, 'local_chronifycompliance');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $DB->insert_record('local_chronifycompliance_finding', ['courseid' => $course->id, 'checkid' => 'requireddeleted',
            'component' => 'local_chronifycompliance', 'severity' => 3, 'fingerprint' => sha1('probe'), 'status' => 'open',
            'messagekey' => 'check:requireddeleted:message', 'fixable' => 0, 'timecreated' => time(),
            'timemodified' => time(), 'lastseen' => time()]);
        finding_store::invalidate_summary((int) $course->id);
        $this->assertSame(1, finding_store::get_summary((int) $course->id)['critical']);

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');
        $this->assertSame((int) $course->id, (int) $PAGE->course->id);
        $this->assertFalse(isset($PAGE->course), 'moodle_page has no __isset, so "$PAGE->course ?? null" is null');

        \core\notification::fetch();
        hook_callbacks::before_http_headers(new \core\hook\output\before_http_headers($PAGE->get_renderer('core')));
        $this->assertNotEmpty(\core\notification::fetch(), 'Critical banner on the course page');
    }

    /**
     * A minimum score of 8 on a package scored out of 10 matches an 80% pass mark.
     */
    public function test_scormquiz_points_against_percent(): void {
        [$course, $scorm] = $this->scorm(['completionscorerequired' => 8]);
        $this->attempts($scorm, ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => 8, 'cmi.core.score.max' => 10]);
        $this->assertSame([], $this->lines((int) $course->id, 'scormquiz'), 'Minimum score 8 of 10 points is the 80% pass mark');
    }

    /**
     * Applying a scormquiz fix never sets a minimum score above the reported maximum.
     */
    public function test_scormquiz_fix_keeps_score_reachable(): void {
        global $DB;
        [$course, $scorm] = $this->scorm(['completionscorerequired' => 8]);
        $this->attempts($scorm, ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => 8, 'cmi.core.score.max' => 10]);
        foreach ($this->findings((int) $course->id, 'scormquiz') as $finding) {
            if ($finding->fixable) {
                fixer::apply($finding->id, [], 2);
            }
        }
        $required = (float) $DB->get_field('scorm', 'completionscorerequired', ['id' => $scorm->id]);
        $after = $this->lines((int) $course->id, 'scormquiz');
        $this->assertLessThanOrEqual(10.0, $required, 'Minimum score after the fix; next audit: ' . implode('; ', $after));
    }

    /**
     * Learners reporting "failed" prove the package reports pass/fail, so "passed" is not "never sent".
     */
    public function test_statusmismatch_with_failed_only(): void {
        [$course, $scorm] = $this->scorm(['completionstatusrequired' => 2]);
        $this->attempts($scorm, ['cmi.core.lesson_status' => 'failed', 'cmi.core.score.raw' => 40]);
        $this->assertSame([], $this->lines((int) $course->id, 'scormstatusmismatch'), 'Nobody passed yet');
    }

    /**
     * The "completes on view" fix is not offered when the attempts show the package sends no status.
     */
    public function test_requireview_fix_on_package_without_status(): void {
        [$course, $scorm] = $this->scorm([], 'scorm12_single');
        $this->set_scorm($scorm, ['completionstatusrequired' => null, 'completionscorerequired' => null], ['completionview' => 1]);
        $this->attempts($scorm, ['cmi.suspend_data' => 'x', 'cmi.core.lesson_location' => '2']);
        $findings = $this->findings((int) $course->id, 'scormrequireview');
        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->fixable, 'Fix offered: ' . json_encode($findings[0]->details['recommended'] ?? null));
    }

    /**
     * No critical "no status" when completion uses the score only.
     */
    public function test_nostatus_with_score_rule(): void {
        [$course, $scorm] = $this->scorm([], 'scorm12_single');
        $this->set_scorm($scorm, ['completionstatusrequired' => null, 'completionscorerequired' => 5]);
        $this->attempts($scorm, ['cmi.core.score.raw' => 7]);
        $this->assertSame([], $this->lines((int) $course->id, 'scormnostatus'), 'Completion by score works without a status');
    }

    /**
     * Undo of an added criterion after the teacher saved the completion settings.
     */
    public function test_undo_after_completion_settings_saved(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $a = $generator->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $b = $generator->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->require_cms((int) $course->id, [$a->cmid]);
        $findings = $this->findings((int) $course->id, 'trackednotrequired');
        $this->assertCount(1, $findings);
        $result = fixer::apply($findings[0]->id, [], 2);
        $this->assertTrue($result->success);

        // Saving the completion settings page deletes every criterion and inserts them again with new ids.
        (new \completion_info($course))->clear_criteria();
        $this->require_cms((int) $course->id, [$a->cmid, $b->cmid]);

        $undo = fixer::undo($result->fixlogid, 2);
        $this->assertTrue($undo->success, 'Undo reported');
        $this->assertFalse(
            $DB->record_exists('course_completion_criteria', ['course' => $course->id, 'moduleinstance' => $b->cmid]),
            'Undo reported success, yet the activity is still required'
        );
    }

    /**
     * A dry run (cli/scan.php without --save) writes nothing.
     */
    public function test_dry_run_writes_nothing(): void {
        global $DB;
        [$course] = $this->scorm([], 'scorm12_single');
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_pkg'));
        course_auditor::audit((int) $course->id, false);
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_pkg'), 'Package analysis stored by a dry run');
    }

    /**
     * Turning a check off removes its findings from the counts.
     */
    public function test_disabled_check_findings(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        course_auditor::audit((int) $course->id, true);
        $this->assertSame(1, finding_store::get_summary((int) $course->id)['total']);

        set_config('enabled_trackingoff', 0, 'local_chronifycompliance');
        check_registry::reset();
        course_auditor::audit((int) $course->id, true);
        $this->assertSame(0, finding_store::get_summary((int) $course->id)['total'], 'Open findings of a check turned off');
    }

    /**
     * After a course is deleted, the privacy API still reaches the fix log rows that hold a user id.
     */
    public function test_fixlog_of_deleted_course(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $manager = $generator->create_user();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        course_auditor::audit((int) $course->id, true);
        $findings = finding_store::get_course_findings((int) $course->id);
        $finding = reset($findings);
        fixer::apply($finding->id, [], (int) $manager->id);

        ob_start();
        delete_course($course, false);
        ob_end_clean();
        $remaining = $DB->count_records('local_chronifycompliance_fixlog', ['userid' => $manager->id]);
        $contexts = provider::get_contexts_for_userid((int) $manager->id)->get_contextids();
        $this->assertTrue(
            $remaining === 0 || $contexts !== [],
            "{$remaining} fix log rows keep the user id after the course is deleted, and the privacy API finds no context"
        );
    }

    /**
     * The admin severity override applies to every finding of the check.
     */
    public function test_severity_override(): void {
        set_config('severity_scormquiz', 3, 'local_chronifycompliance');
        [$course] = $this->scorm([]);
        $findings = $this->findings((int) $course->id, 'scormquiz');
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->severity, 'Admin set scormquiz to Critical; finding ' . $findings[0]->messagekey);
    }

    /**
     * Removing the criterion of a deleted activity: the criterion goes, completion is not recalculated.
     */
    public function test_requireddeleted_fix_unblocks_learner(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $a = $generator->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->require_cms((int) $course->id, [$a->cmid]);
        $DB->insert_record('course_completion_criteria', ['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'module' => 'assign', 'moduleinstance' => 999999]);
        $learner = $generator->create_and_enrol($course, 'student');
        $cm = get_fast_modinfo($course)->get_cm($a->cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int) $learner->id);
        $this->run_completion_cron();
        $complete = fn() => (new \completion_completion(['userid' => $learner->id, 'course' => $course->id]))->is_complete();
        $this->assertFalse($complete(), 'Blocked by the missing activity');

        $findings = $this->findings((int) $course->id, 'requireddeleted');
        $this->assertCount(1, $findings);
        fixer::apply($findings[0]->id, [], 2);
        $this->run_completion_cron();
        $this->assertSame([], $this->findings((int) $course->id, 'requireddeleted'), 'The broken criterion is gone');
        // Decision D15: like Moodle's own completion settings page, the fix does not recalculate completion,
        // so this learner stays in progress until Moodle next updates their course completion.
        $this->assertFalse($complete(), 'The fix does not recalculate completion (decision D15)');
    }

    /**
     * A non-editing teacher in separate groups cannot diagnose a learner of another group.
     */
    public function test_learner_diagnostic_separate_groups(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $learner = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $g1->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $g2->id, 'userid' => $learner->id]);
        $this->setUser($teacher);
        $this->expectException(\moodle_exception::class);
        explain_user_completion::execute((int) $course->id, (int) $learner->id);
    }

    /**
     * Course A gets the same prerequisite result whatever order the courses are audited in.
     */
    public function test_prereq_order(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $a = $generator->create_course(['enablecompletion' => 1]);
        $b = $generator->create_course(['enablecompletion' => 1]);
        $c = $generator->create_course(['enablecompletion' => 1]);
        foreach ([[$a, $b], [$b, $c]] as [$course, $prereq]) {
            $DB->insert_record('course_completion_criteria', ['course' => $course->id,
                'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseinstance' => $prereq->id]);
        }
        $DB->insert_record('course_completion_criteria', ['course' => $c->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'module' => 'assign', 'moduleinstance' => 999999]);

        foreach ([$a, $b, $c] as $course) {
            course_auditor::audit((int) $course->id, true);
        }
        $first = finding_store::get_summary((int) $a->id)['critical'];
        foreach ([$c, $b, $a] as $course) {
            course_auditor::audit((int) $course->id, true);
        }
        $second = finding_store::get_summary((int) $a->id)['critical'];
        $this->assertSame($second, $first, 'Critical findings of course A: first pass A, B, C; second pass C, B, A');
    }

    /**
     * Activity names in messages go through format_string (multi-language tags, filters).
     */
    public function test_names_formatted_in_messages(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $name = '<span lang="en" class="multilang">Task EN</span><span lang="es" class="multilang">Task ES</span>';
        $a = $generator->create_module('assign', ['course' => $course->id, 'name' => $name,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->require_cms((int) $course->id, [$a->cmid]);
        $findings = $this->findings((int) $course->id, 'weakcondition');
        $this->assertCount(1, $findings);
        $this->assertStringNotContainsString('<span', $findings[0]->get_message());
    }

    /**
     * Creating a course queues no work (README: "Installing it starts no work").
     */
    public function test_new_course_queues_nothing(): void {
        global $DB;
        $this->getDataGenerator()->create_course();
        $this->assertSame(
            0,
            $DB->count_records('task_adhoc', ['classname' => '\\local_chronifycompliance\\task\\check_links']),
            'Link check tasks queued by creating one course'
        );
    }

    /**
     * The three site checks are part of the core status report and run without errors.
     */
    public function test_site_checks_in_status_report(): void {
        $ids = [];
        foreach (\core\check\manager::get_checks('status') as $check) {
            if ($check->get_component() === 'local_chronifycompliance') {
                $ids[] = $check->get_id();
                $check->get_result();
            }
        }
        sort($ids);
        $this->assertSame(['completiondisabled', 'completiontasks', 'cronstale'], $ids);

        $ids = [];
        foreach (\core\check\manager::get_checks('security') as $check) {
            if ($check->get_component() === 'local_chronifycompliance') {
                $ids[] = $check->get_id();
            }
        }
        sort($ids);
        $this->assertSame(
            ['adminmfa', 'debugdisplay', 'exposedfiles', 'guestaccess', 'inactiveadmins', 'passwordstrength', 'riskyroles',
                'selfregistration', 'sessioncookies', 'webservices', 'wstokens'],
            $ids
        );
    }
}
