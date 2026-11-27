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

namespace local_chronifycompliance\check\scorm;

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\scorm_check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\scorm\inspector::class)]
/**
 * Tests of the SCORM check fixes, using the demo scenarios.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\scorm_check
 * @covers \local_chronifycompliance\scorm\inspector
 */
final class scorm_checks_test extends \advanced_testcase {
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
     * Create a demo scenario course.
     *
     * @param string $id
     * @return \stdClass
     */
    private function scenario(string $id): \stdClass {
        $class = '\\local_chronifycompliance\\tests\\demo\\scenario\\' . $id;
        return (new $class())->create($this->getDataGenerator(), (int) $this->getDataGenerator()->create_category()->id);
    }

    /**
     * Stored findings of one check.
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
     * Apply, re-audit, undo, re-audit.
     *
     * @param string $scenario
     * @param callable $assertfixed Called with the scorm record after the fix.
     */
    private function fix_and_undo(string $scenario, callable $assertfixed): void {
        global $DB;
        $course = $this->scenario($scenario);
        $findings = $this->audit((int) $course->id, $scenario);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->fixable);
        $preview = fixer::preview($findings[0]->id);
        $this->assertNotEmpty($preview->rows);
        $result = fixer::apply($findings[0]->id, [], 2);
        $scorm = $DB->get_record('scorm', ['course' => $course->id]);
        $assertfixed($scorm);
        $this->assertSame([], $this->audit((int) $course->id, $scenario), 'Fixed on the next audit');
        fixer::undo($result->fixlogid, 2);
        $this->assertCount(1, $this->audit((int) $course->id, $scenario), 'Back after undo');
    }

    /**
     * Status that is never sent: switch to the reported one.
     */
    public function test_fix_statusmismatch(): void {
        $this->fix_and_undo('scormstatusmismatch', fn($scorm) => $this->assertEquals(4, $scorm->completionstatusrequired));
    }

    /**
     * Completion on view: add a status rule.
     */
    public function test_fix_requireview(): void {
        $this->fix_and_undo('scormrequireview', fn($scorm) => $this->assertGreaterThan(0, $scorm->completionstatusrequired));
    }

    /**
     * Multi-SCO: require all SCOs.
     */
    public function test_fix_multisco(): void {
        $this->fix_and_undo('scormmultisco', fn($scorm) => $this->assertEquals(1, $scorm->completionstatusallscos));
    }

    /**
     * Score and pass grade: align the grade to pass.
     */
    public function test_fix_passgrade(): void {
        global $DB;
        $this->fix_and_undo('scormpassgrade', function ($scorm) use ($DB): void {
            $this->assertEquals(80, $DB->get_field('grade_items', 'gradepass', ['itemmodule' => 'scorm',
                'iteminstance' => $scorm->id]));
        });
    }

    /**
     * No status: not fixable. No SCO: broken package reported.
     */
    public function test_nostatus_and_broken(): void {
        global $DB;
        $course = $this->scenario('scormnostatus');
        $findings = $this->audit((int) $course->id, 'scormnostatus');
        $this->assertFalse($findings[0]->fixable);
        $this->assertStringContainsString('last 5 attempts', $findings[0]->get_message());

        $scormid = $DB->get_field('scorm', 'id', ['course' => $course->id]);
        $DB->delete_records('scorm_scoes', ['scorm' => $scormid]);
        $findings = $this->audit((int) $course->id, 'scormstatusmismatch');
        $this->assertSame('check:scormstatusmismatch:messagenosco', $findings[0]->messagekey);
    }

    /**
     * The package is analyzed once for several activities using it.
     */
    public function test_package_analyzed_once(): void {
        global $DB;
        $course = $this->scenario('scormmultisco');
        $generator = $this->getDataGenerator();
        $path = \core_component::get_component_directory('local_chronifycompliance')
            . '/tests/fixtures/packages/scorm12_multisco.zip';
        $generator->create_module('scorm', ['course' => $course->id, 'packagefilepath' => $path]);
        course_auditor::audit((int) $course->id, true);
        $this->assertSame(1, $DB->count_records('local_chronifycompliance_pkg'));
    }
}
