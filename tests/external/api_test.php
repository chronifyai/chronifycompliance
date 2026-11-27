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

namespace local_completiondoctor\external;

use core_external\external_api;
use local_completiondoctor\check\check_registry;
use local_completiondoctor\check\course_auditor;

/**
 * Tests of the API web services: findings, SCORM and package analysis, learner explanation, service.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_findings::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(analyze_scorm::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(analyze_package::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(explain_user_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scorm_exporter::class)]
final class api_test extends \core_external\tests\externallib_testcase {
    /**
     * Prepare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        check_registry::reset();
        $this->setAdminUser();
    }

    /**
     * Create the course of a demo scenario.
     *
     * @param string $id
     * @param int|null $categoryid
     * @return \stdClass
     */
    private function scenario(string $id, ?int $categoryid = null): \stdClass {
        $class = '\\local_completiondoctor\\tests\\demo\\scenario\\' . $id;
        return (new $class())->create(
            $this->getDataGenerator(),
            $categoryid ?? (int) $this->getDataGenerator()->create_category()->id
        );
    }

    /**
     * Filters, paging and permissions of get_findings.
     */
    public function test_get_findings(): void {
        $category = $this->getDataGenerator()->create_category();
        $a = $this->scenario('nocriteria', (int) $category->id);
        $b = $this->scenario('weakcondition');
        course_auditor::audit((int) $a->id, true);
        course_auditor::audit((int) $b->id, true);

        $all = external_api::clean_returnvalue(get_findings::execute_returns(), get_findings::execute());
        $this->assertSame(2, $all['total']);
        $critical = get_findings::execute(0, 3);
        $this->assertSame(['nocriteria'], array_column($critical['findings'], 'checkid'));
        $this->assertSame(1, get_findings::execute((int) $category->id)['total']);
        $this->assertSame(1, get_findings::execute(0, 1, 'weakcondition')['total']);
        $paged = get_findings::execute(0, 1, '', 'open', 1, 1);
        $this->assertCount(1, $paged['findings']);
        $this->assertSame(0, get_findings::execute(0, 1, '', 'ignored')['total']);

        try {
            get_findings::execute(0, 1, '', 'bogus');
            $this->fail('Invalid status accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('Invalid status', $e->getMessage());
        }

        $teacher = $this->getDataGenerator()->create_and_enrol($a, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        get_findings::execute();
    }

    /**
     * SCORM activity analysis with recommendation.
     */
    public function test_analyze_scorm(): void {
        $course = $this->scenario('scormstatusmismatch');
        $cm = array_values(get_fast_modinfo($course)->get_instances_of('scorm'))[0];
        // The service only reads; analyzing again is queued and runs in the background.
        $result = external_api::clean_returnvalue(analyze_scorm::execute_returns(), analyze_scorm::execute((int) $cm->id));
        $this->assertSame('pending', $result['status']);
        analyze_scorm::execute((int) $cm->id, true);
        $this->expectOutputRegex('/./');
        $this->runAdhocTasks(\local_completiondoctor\task\check_course::class);
        $result = external_api::clean_returnvalue(analyze_scorm::execute_returns(), analyze_scorm::execute((int) $cm->id));
        $this->assertSame('ok', $result['status']);
        $this->assertSame('storyline', $result['tool']);
        $this->assertSame('completed-incomplete', $result['reportingmode']);
        $this->assertSame('medium', $result['confidence']);
        $changed = array_values(array_filter($result['settings'], fn($s) => $s['changed']));
        $this->assertSame('completionstatusrequired', $changed[0]['field']);
        $this->assertNotEmpty(json_decode($result['analysisjson'], true));

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        analyze_scorm::execute((int) $cm->id);
    }

    /**
     * A non-SCORM course module is refused.
     */
    public function test_analyze_scorm_wrong_module(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->expectException(\moodle_exception::class);
        analyze_scorm::execute((int) $page->cmid);
    }

    /**
     * Analysis of an uploaded package.
     */
    public function test_analyze_package(): void {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $draftitemid, 'filepath' => '/', 'filename' => 'course.zip',
        ], __DIR__ . '/../fixtures/packages/multilang_diff.zip');
        $result = external_api::clean_returnvalue(analyze_package::execute_returns(), analyze_package::execute($draftitemid));
        $this->assertTrue($result['sharedbuilds']);
        $this->assertNotEmpty($result['builddifferences']);
        $this->assertSame('storyline', $result['tool']);

        $this->expectException(\invalid_parameter_exception::class);
        analyze_package::execute(file_get_unused_draft_itemid());
    }

    /**
     * Learner explanation: enrolled learners only, with blocking conditions.
     */
    public function test_explain_user_completion(): void {
        $course = $this->scenario('healthy');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $result = external_api::clean_returnvalue(
            explain_user_completion::execute_returns(),
            explain_user_completion::execute((int) $course->id, (int) $student->id)
        );
        $this->assertFalse($result['completed']);
        $this->assertSame(1, $result['blocking']);
        $this->assertSame('all', $result['aggregation']);
        $this->assertTrue($result['criteria'][0]['blocking']);
        $this->assertGreaterThan(0, $result['criteria'][0]['cmid']);

        $outsider = $this->getDataGenerator()->create_user();
        try {
            explain_user_completion::execute((int) $course->id, (int) $outsider->id);
            $this->fail('Users who are not enrolled must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('learner:notenrolled', $e->errorcode);
        }

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        explain_user_completion::execute((int) $course->id, (int) $student->id);
    }

    /**
     * The pre-built service exists, is disabled by default and contains every plugin function.
     */
    public function test_service_definition(): void {
        global $DB;
        $service = $DB->get_record('external_services', ['shortname' => 'local_completiondoctor'], '*', MUST_EXIST);
        $this->assertEquals(0, $service->enabled);
        $this->assertEquals(1, $service->restrictedusers);
        $functions = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = ?',
            [$service->id]
        );
        $plugin = $DB->get_fieldset_select('external_functions', 'name', 'component = ?', ['local_completiondoctor']);
        $this->assertCount(11, $plugin);
        foreach ($plugin as $name) {
            $this->assertContains($name, $functions);
        }
    }
}
