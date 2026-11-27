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

namespace local_chronifycompliance\check;

use local_chronifycompliance\check\completion\passgradeunreachable;
use local_chronifycompliance\check\scorm\scormgradenoscore;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;

/**
 * Tests of the grade rule checks: SCORM requiring a grade without a score, and unreachable pass grades.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\scorm\scormgradenoscore
 * @covers \local_chronifycompliance\check\completion\passgradeunreachable
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scormgradenoscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(passgradeunreachable::class)]
final class grade_rules_test extends \advanced_testcase {
    /**
     * Prepare.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        check_registry::reset();
    }

    /**
     * Findings of one check.
     *
     * @param int $courseid
     * @param string $checkid
     * @return finding[]
     */
    private function audit(int $courseid, string $checkid): array {
        $result = course_auditor::audit($courseid, true, [$checkid]);
        $this->assertSame([], $result->errors);
        return array_values(array_filter(finding_store::get_course_findings($courseid), fn($f) => $f->checkid === $checkid));
    }

    /**
     * A course with one SCORM activity.
     *
     * @param string $fixture
     * @param array $settings
     * @param float $gradepass
     * @return array [course, scorm module]
     */
    private function scorm(string $fixture, array $settings, float $gradepass = 0): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $path = \core_component::get_component_directory('local_chronifycompliance') . "/tests/fixtures/packages/{$fixture}.zip";
        $scorm = $generator->create_module('scorm', $settings + ['course' => $course->id, 'packagefilepath' => $path,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionstatusrequired' => null,
            'grademethod' => GRADEHIGHEST]);
        if ($gradepass) {
            $DB->set_field('grade_items', 'gradepass', $gradepass, ['itemmodule' => 'scorm', 'iteminstance' => $scorm->id]);
        }
        return [$course, $scorm];
    }

    /**
     * Completion state of a learner after the package reported a status (no score), as the player does.
     *
     * @param \stdClass $course
     * @param \stdClass $scorm
     * @param string $status Reported lesson status.
     * @return int
     */
    private function finish(\stdClass $course, \stdClass $scorm, string $status = 'completed'): int {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        scorm_insert_track(
            $user->id,
            $scorm->id,
            $scoid,
            scorm_get_attempt($user->id, $scorm->id, 1),
            'cmi.core.lesson_status',
            $status
        );
        $cm = get_fast_modinfo($course->id)->get_cm($scorm->cmid);
        return (int) (new \completion_info($course))->get_data($cm, false, $user->id)->completionstate;
    }

    /**
     * Grade to pass on a package without a score: critical; a learner who finishes fails; the fix makes them complete.
     */
    public function test_scorm_blocked_and_fix(): void {
        [$course, $scorm] = $this->scorm('storyline_noquiz', [], 50);
        $findings = $this->audit((int) $course->id, 'scormgradenoscore');
        $this->assertCount(1, $findings);
        $this->assertSame('check:scormgradenoscore:messageblocked', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $this->finish($course, $scorm), 'Moodle: grade 0, always a fail');

        $preview = fixer::preview($findings[0]->id);
        $this->assertCount(2, $preview->rows);
        $result = fixer::apply($findings[0]->id, [], 2);
        $this->assertSame([], $this->audit((int) $course->id, 'scormgradenoscore'));
        $this->assertSame(COMPLETION_COMPLETE, $this->finish($course, $scorm), 'After the fix a learner who finishes completes');

        fixer::undo($result->fixlogid, 2);
        $this->assertCount(1, $this->audit((int) $course->id, 'scormgradenoscore'));
    }

    /**
     * Without a grade to pass: info. Opening gives no grade; finishing records 0, which completes.
     */
    public function test_scorm_zero(): void {
        [$course, $scorm] = $this->scorm('storyline_noquiz', []);
        $findings = $this->audit((int) $course->id, 'scormgradenoscore');
        $this->assertSame('check:scormgradenoscore:messagezero', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_INFO, $findings[0]->severity);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->finish($course, $scorm, 'incomplete'), 'Only opened: no grade');
        $this->assertSame(COMPLETION_COMPLETE, $this->finish($course, $scorm), 'Finished: grade 0 completes');
    }

    /**
     * Quiet: a package with a quiz, grading by learning objects, an unknown tool without evidence.
     */
    public function test_scorm_quiet(): void {
        [$course] = $this->scorm('storyline_fake', [], 50);
        $this->assertSame([], $this->audit((int) $course->id, 'scormgradenoscore'), 'Package has a quiz');
        [$course] = $this->scorm('storyline_noquiz', ['grademethod' => GRADESCOES], 1);
        $this->assertSame([], $this->audit((int) $course->id, 'scormgradenoscore'), 'Learning objects grading');
        [$course] = $this->scorm('scorm12_single', [], 50);
        $this->assertSame([], $this->audit((int) $course->id, 'scormgradenoscore'), 'Unknown tool, no attempts');
    }

    /**
     * An unknown tool is reported once learners' attempts show statuses but never a score.
     */
    public function test_scorm_evidence(): void {
        [$course, $scorm] = $this->scorm('scorm12_single', [], 50);
        for ($i = 0; $i < 5; $i++) {
            $this->finish($course, $scorm);
        }
        \cache::make('local_chronifycompliance', 'evidence')->purge();
        $findings = $this->audit((int) $course->id, 'scormgradenoscore');
        $this->assertCount(1, $findings);
        $this->assertSame('check:scormgradenoscore:basis:evidence', $findings[0]->messageparams['basis']['str']);
    }

    /**
     * Grade to pass above the maximum grade: critical, fixed with a value the teacher enters.
     */
    public function test_passgrade_unreachable_and_fix(): void {
        global $DB;
        $course = (new \local_chronifycompliance\tests\demo\scenario\passgradeunreachable())
            ->create($this->getDataGenerator(), (int) $this->getDataGenerator()->create_category()->id);
        $findings = $this->audit((int) $course->id, 'passgradeunreachable');
        $this->assertCount(1, $findings);
        $this->assertSame('check:passgradeunreachable:messageactivity', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertSame(40.0, (new passgradeunreachable())->get_fix_parameters($findings[0])[0]['default']);

        $result = fixer::apply($findings[0]->id, ['gradepass' => 45], 2);
        $this->assertEquals(45, $DB->get_field('grade_items', 'gradepass', ['itemmodule' => 'assign']));
        $this->assertSame([], $this->audit((int) $course->id, 'passgradeunreachable'));
        fixer::undo($result->fixlogid, 2);
        $this->assertCount(1, $this->audit((int) $course->id, 'passgradeunreachable'));
    }

    /**
     * Empty quiz (no marks) and the course grade condition.
     */
    public function test_quiz_empty_and_course_grade(): void {
        global $CFG;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_grade.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('quiz', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1]);
        $findings = $this->audit((int) $course->id, 'passgradeunreachable');
        $this->assertSame('check:passgradeunreachable:messagequizempty', $findings[0]->messagekey);
        $this->assertFalse($findings[0]->fixable);

        $courseitem = \grade_item::fetch_course_item($course->id);
        $criterion = new \completion_criteria_grade(['course' => $course->id, 'gradepass' => $courseitem->grademax + 10]);
        $criterion->insert();
        $messages = array_map(fn($f) => $f->messagekey, $this->audit((int) $course->id, 'passgradeunreachable'));
        $this->assertContains('check:passgradeunreachable:messagecourse', $messages);
    }
}
