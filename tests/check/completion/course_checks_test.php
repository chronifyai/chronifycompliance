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

namespace local_chronifycompliance\check\completion;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\tests\demo\scenario;
use local_chronifycompliance\local\finding_store;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\course_check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\availability\tree_validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\availability\dependency_graph::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\fix\criteria_editor::class)]
/**
 * Tests of the course checks: fixes with undo, and edge cases not covered by the demo scenarios.
 *
 * The demo scenarios (tested in seeder_test) prove that each check fires on its bad course and that
 * the healthy course is clean.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\course_check
 * @covers \local_chronifycompliance\availability\tree_validator
 * @covers \local_chronifycompliance\availability\dependency_graph
 * @covers \local_chronifycompliance\fix\criteria_editor
 */
final class course_checks_test extends \advanced_testcase {
    /** @var int Demo category. */
    private int $categoryid;

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
        $this->categoryid = (int) $this->getDataGenerator()->create_category()->id;
    }

    /**
     * Reset the registry.
     */
    protected function tearDown(): void {
        check_registry::reset();
        parent::tearDown();
    }

    /**
     * Create the course of a demo scenario.
     *
     * @param string $id Scenario id.
     * @return \stdClass
     */
    private function scenario(string $id): \stdClass {
        $class = '\\local_chronifycompliance\\tests\\demo\\scenario\\' . $id;
        /** @var scenario $scenario */
        $scenario = new $class();
        return $scenario->create($this->getDataGenerator(), $this->categoryid);
    }

    /**
     * Audit one check on a course and store the findings.
     *
     * @param int $courseid
     * @param string $checkid
     * @return finding[]
     */
    private function audit(int $courseid, string $checkid): array {
        $result = course_auditor::audit($courseid, true, [$checkid]);
        $this->assertSame([], $result->errors);
        return array_values(array_filter(
            finding_store::get_course_findings($courseid),
            fn(finding $f) => $f->checkid === $checkid
        ));
    }

    /**
     * Run a check without storing anything.
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
     * Apply the fix of the only finding, check it is gone on the next audit, then undo it.
     *
     * @param int $courseid
     * @param string $checkid
     * @param array $params
     * @return int Fix log id (already undone).
     */
    private function fix_and_undo(int $courseid, string $checkid, array $params = []): int {
        $findings = $this->audit($courseid, $checkid);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->fixable);
        $result = fixer::apply($findings[0]->id, $params, 2);
        $this->assertTrue($result->success);
        $this->assertSame([], $this->audit($courseid, $checkid), 'Finding must be gone after the fix');

        $undo = fixer::undo($result->fixlogid, 2);
        $this->assertTrue($undo->success);
        $this->assertCount(1, $this->audit($courseid, $checkid), 'Finding must be back after undo');
        return $result->fixlogid;
    }

    /**
     * Enable completion tracking, and undo.
     */
    public function test_fix_trackingoff(): void {
        global $DB;
        $course = $this->scenario('trackingoff');
        $findings = $this->audit((int) $course->id, 'trackingoff');
        fixer::apply($findings[0]->id, [], 2);
        $this->assertEquals(1, $DB->get_field('course', 'enablecompletion', ['id' => $course->id]));
        $this->fix_undo_last((int) $course->id);
        $this->assertEquals(0, $DB->get_field('course', 'enablecompletion', ['id' => $course->id]));
    }

    /**
     * Undo the last fix of a course.
     *
     * @param int $courseid
     */
    private function fix_undo_last(int $courseid): void {
        global $DB;
        $logs = $DB->get_records('local_chronifycompliance_fixlog', ['courseid' => $courseid, 'undone' => 0], 'id DESC');
        $this->assertTrue(fixer::undo((int) reset($logs)->id, 2)->success);
    }

    /**
     * Add a key activity to the criteria, and undo.
     */
    public function test_fix_trackednotrequired(): void {
        global $DB;
        $course = $this->scenario('trackednotrequired');
        $this->fix_and_undo((int) $course->id, 'trackednotrequired');
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $course->id]));
    }

    /**
     * Activity pass grade: parameters are validated, the fix sets it, undo restores 0.
     */
    public function test_fix_nopassgrade_activity(): void {
        $course = $this->scenario('nopassgrade');
        $findings = $this->audit((int) $course->id, 'nopassgrade');
        $check = check_registry::get_check('nopassgrade');
        $this->assertSame('gradepass', $check->get_fix_parameters($findings[0])[0]['name']);
        foreach ([[], ['gradepass' => 0], ['gradepass' => 150]] as $params) {
            try {
                fixer::preview($findings[0]->id, $params);
                $this->fail('Invalid pass grade accepted: ' . json_encode($params));
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('100', $e->getMessage());
            }
        }
        $this->fix_and_undo((int) $course->id, 'nopassgrade', ['gradepass' => 60]);
    }

    /**
     * Course grade criterion without a pass grade.
     */
    public function test_nopassgrade_course_criterion(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $id = $DB->insert_record('course_completion_criteria', ['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE, 'gradepass' => null]);
        $findings = $this->run_check((int) $course->id, 'nopassgrade');
        $this->assertSame('check:nopassgrade:messagecourse', $findings[0]->messagekey);
        $this->fix_and_undo((int) $course->id, 'nopassgrade', ['gradepass' => 50]);
        $this->assertEquals(0, (float) $DB->get_field('course_completion_criteria', 'gradepass', ['id' => $id]));
    }

    /**
     * Hidden grade item: warning and fix; visible grade item: info.
     */
    public function test_overlapgrade(): void {
        global $DB;
        $course = $this->scenario('overlapgrade');
        $findings = $this->run_check((int) $course->id, 'overlapgrade');
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);
        $this->fix_and_undo((int) $course->id, 'overlapgrade');

        $DB->set_field('grade_items', 'hidden', 0, ['courseid' => $course->id, 'itemtype' => 'mod']);
        $findings = $this->run_check((int) $course->id, 'overlapgrade');
        $this->assertSame(base_check::SEVERITY_INFO, $findings[0]->severity);
        $this->assertSame('check:overlapgrade:messagevisible', $findings[0]->messagekey);
    }

    /**
     * Add the self completion block, and undo.
     */
    public function test_fix_selfcompletionblock(): void {
        global $DB;
        $course = $this->scenario('selfcompletionblock');
        $context = \context_course::instance($course->id);

        // New installs disable the block: reported, but not fixable by adding it.
        $DB->set_field('block', 'visible', 0, ['name' => 'selfcompletion']);
        $findings = $this->run_check((int) $course->id, 'selfcompletionblock');
        $this->assertSame('check:selfcompletionblock:messagedisabled', $findings[0]->messagekey);
        $this->assertFalse($findings[0]->fixable);

        $DB->set_field('block', 'visible', 1, ['name' => 'selfcompletion']);
        $findings = $this->audit((int) $course->id, 'selfcompletionblock');
        $result = fixer::apply($findings[0]->id, [], 2);
        $this->assertTrue($DB->record_exists('block_instances', ['blockname' => 'selfcompletion',
            'parentcontextid' => $context->id]));
        $this->assertSame([], $this->audit((int) $course->id, 'selfcompletionblock'));
        fixer::undo($result->fixlogid, 2);
        $this->assertFalse($DB->record_exists('block_instances', ['blockname' => 'selfcompletion',
            'parentcontextid' => $context->id]));
    }

    /**
     * Remove a broken criterion through the core criteria API, and restore it (with a new id).
     */
    public function test_fix_requireddeleted(): void {
        global $DB;
        $course = $this->scenario('requireddeleted');
        $findings = $this->audit((int) $course->id, 'requireddeleted');
        $criterion = $DB->get_record('course_completion_criteria', ['id' => $findings[0]->details['criterionid']]);
        $this->fix_and_undo((int) $course->id, 'requireddeleted');
        $this->assertTrue($DB->record_exists('course_completion_criteria', ['course' => $course->id,
            'criteriatype' => $criterion->criteriatype, 'moduleinstance' => $criterion->moduleinstance]));
    }

    /**
     * Restriction trees: AND fails on any broken condition, OR only when all are broken.
     */
    public function test_tree_validator(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $context = new audit_context(get_course($course->id));
        $validator = new \local_chronifycompliance\availability\tree_validator($context, 1000);
        $missinggroup = ['type' => 'group', 'id' => 999999];
        $anygroup = ['type' => 'group'];
        $past = ['type' => 'date', 'd' => '<', 't' => 500];
        $future = ['type' => 'date', 'd' => '>=', 't' => 5000];

        $tree = fn(string $op, array $c) => json_encode(['op' => $op, 'c' => $c, 'showc' => array_fill(0, count($c), true)]);
        $this->assertTrue($validator->validate($tree('&', [$anygroup, $missinggroup]))['unsatisfiable']);
        $this->assertFalse($validator->validate($tree('|', [$anygroup, $missinggroup]))['unsatisfiable']);
        $this->assertTrue($validator->validate($tree('|', [$past, $missinggroup]))['unsatisfiable']);
        $this->assertFalse($validator->validate($tree('&', [$future]))['unsatisfiable']);
        $this->assertFalse($validator->validate($tree('!&', [$missinggroup]))['unsatisfiable']);
        $nested = json_encode(['op' => '&', 'c' => [['op' => '|', 'c' => [$past, $missinggroup]]], 'showc' => [true]]);
        $result = $validator->validate($nested);
        $this->assertTrue($result['unsatisfiable']);
        $this->assertCount(2, $result['reasons']);
        $this->assertFalse($validator->validate(null)['unsatisfiable']);
    }

    /**
     * Stealth activities are a warning, hidden sections critical.
     */
    public function test_unreachable_severity(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $assign = $generator->create_module('assign', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1]);
        $config = (object) ['id' => $course->id, 'criteria_activity' => [$assign->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);
        $this->assertSame([], $this->run_check((int) $course->id, 'unreachable'));

        set_config('allowstealth', 1);
        set_coursemodule_visible($assign->cmid, 1, 0);
        $findings = $this->run_check((int) $course->id, 'unreachable');
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);

        $section = get_fast_modinfo($course)->get_section_info(1);
        if (method_exists(\core_courseformat\local\sectionactions::class, 'set_visibility')) {
            \core_courseformat\formatactions::section($course->id)->set_visibility($section, false);
        } else {
            set_section_visible($course->id, 1, 0);
        }
        $findings = $this->run_check((int) $course->id, 'unreachable');
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertStringContainsString('section is hidden', $findings[0]->get_message());
    }

    /**
     * Cycles through "previous activity" and long cycles are found; OR dependencies are not cycles.
     */
    public function test_circular_variants(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $pages = [];
        for ($i = 0; $i < 3; $i++) {
            $pages[] = (int) $generator->create_module('page', ['course' => $course->id,
                'completion' => COMPLETION_TRACKING_MANUAL])->cmid;
        }
        $needs = fn(array $cmids, string $op = '&') => json_encode(['op' => $op, 'c' => array_map(
            fn($cmid) => ['type' => 'completion', 'cm' => $cmid, 'e' => 1],
            $cmids
        ), 'showc' => array_fill(0, count($cmids), true)]);
        $set = function (int $cmid, string $json) use ($DB, $course): void {
            $DB->set_field('course_modules', 'availability', $json, ['id' => $cmid]);
            rebuild_course_cache($course->id, true);
        };

        // Page 1 needs the previous activity (page 0), page 0 needs page 2, page 2 needs page 1.
        $set($pages[1], $needs([-1]));
        $set($pages[0], $needs([$pages[2]]));
        $set($pages[2], $needs([$pages[1]]));
        $findings = $this->run_check((int) $course->id, 'circular');
        $this->assertCount(1, $findings);
        $this->assertSame($pages, $findings[0]->details['cmids']);

        // As an OR with an unrelated activity, there is no dead lock.
        $other = (int) $generator->create_module('page', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL])->cmid;
        $set($pages[2], $needs([$pages[1], $other], '|'));
        $this->assertSame([], $this->run_check((int) $course->id, 'circular'));
    }

    /**
     * Courses that require each other are a cycle.
     */
    public function test_circular_courses(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $a = $generator->create_course(['enablecompletion' => 1]);
        $b = $generator->create_course(['enablecompletion' => 1]);
        foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
            $DB->insert_record('course_completion_criteria', ['course' => $from->id,
                'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseinstance' => $to->id]);
        }
        $findings = $this->run_check((int) $a->id, 'circular');
        $this->assertSame('check:circular:messagecourses', $findings[0]->messagekey);
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $a->id], $findings[0]->details['courseids']);
    }

    /**
     * Activity aggregation "any" disables trackednotrequired.
     */
    public function test_trackednotrequired_any_aggregation(): void {
        global $DB;
        $course = $this->scenario('trackednotrequired');
        $this->assertCount(1, $this->run_check((int) $course->id, 'trackednotrequired'));
        $DB->insert_record('course_completion_aggr_methd', ['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'method' => COMPLETION_AGGREGATION_ANY]);
        $this->assertSame([], $this->run_check((int) $course->id, 'trackednotrequired'));
    }

    /**
     * A role assigned in a parent category counts.
     */
    public function test_manualrole_parent_assignment(): void {
        $course = $this->scenario('manualrole');
        $this->assertCount(1, $this->run_check((int) $course->id, 'manualrole'));
        $roleid = (int) array_values(array_filter(
            (new audit_context(get_course($course->id)))->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE)
        ))[0]->role;
        role_assign($roleid, $this->getDataGenerator()->create_user()->id, \context_coursecat::instance($this->categoryid));
        $this->assertSame([], $this->run_check((int) $course->id, 'manualrole'));
    }

    /**
     * Manual, view-only and strong rules.
     */
    public function test_weakcondition_variants(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $manual = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $assign = $generator->create_module('assign', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1]);
        $config = (object) ['id' => $course->id, 'criteria_activity' => [$manual->cmid => 1, $assign->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);
        $findings = $this->run_check((int) $course->id, 'weakcondition');
        $this->assertCount(1, $findings);
        $this->assertSame('check:weakcondition:messagemanual', $findings[0]->messagekey);
        $this->assertEquals($manual->cmid, $findings[0]->cmid);
    }

    /**
     * Without activities, a course without criteria is only informational.
     */
    public function test_nocriteria_without_activities(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $findings = $this->run_check((int) $course->id, 'nocriteria');
        $this->assertSame(base_check::SEVERITY_INFO, $findings[0]->severity);
    }

    /**
     * A prerequisite course without criteria or tracking is reported; a deleted one too. The result
     * depends only on the prerequisite's own settings, never on its stored findings.
     */
    public function test_prereqbroken_variants(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $target = $generator->create_course(['enablecompletion' => 1]);
        $DB->insert_record('course_completion_criteria', ['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseinstance' => $target->id]);
        $findings = $this->run_check((int) $course->id, 'prereqbroken');
        $this->assertSame('check:prereqbroken:messagenocriteria', $findings[0]->messagekey);
        $this->assertStringContainsString($target->fullname, $findings[0]->get_message());

        $page = $generator->create_module('page', ['course' => $target->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $config = (object) ['id' => $target->id, 'criteria_activity' => [$page->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);
        $this->assertSame([], $this->run_check((int) $course->id, 'prereqbroken'));

        $DB->set_field('course', 'enablecompletion', 0, ['id' => $target->id]);
        $findings = $this->run_check((int) $course->id, 'prereqbroken');
        $this->assertSame('check:prereqbroken:messagenotracking', $findings[0]->messagekey);

        // Core removes the criterion when the course is deleted; a dangling id comes from old data or restores.
        $DB->set_field('course_completion_criteria', 'courseinstance', $target->id + 1000, ['course' => $course->id]);
        $findings = $this->run_check((int) $course->id, 'prereqbroken');
        $this->assertSame('check:prereqbroken:messagemissing', $findings[0]->messagekey);
    }
}
