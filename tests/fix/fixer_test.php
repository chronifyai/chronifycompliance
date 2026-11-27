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

namespace local_chronifycompliance\fix;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\tests\check_testcase_trait;
use local_chronifycompliance\tests\fake_check;

/**
 * Tests for the fixer and the fix log.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\fix\fixer
 * @covers \local_chronifycompliance\fix\fix_log
 */
#[\PHPUnit\Framework\Attributes\CoversClass(fixer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(fix_log::class)]
final class fixer_test extends \advanced_testcase {
    use check_testcase_trait;

    /**
     * Register the fake check.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->register_checks([new fake_check()]);
    }

    /**
     * Reset the registry.
     */
    protected function tearDown(): void {
        $this->reset_checks();
        parent::tearDown();
    }

    /**
     * Create a course with one stored finding.
     *
     * @param bool $fixable
     * @return array [course, finding]
     */
    private function create_finding(bool $fixable = true): array {
        $course = $this->getDataGenerator()->create_course(['showreports' => 0]);
        fake_check::$runner = fn(audit_context $c, fake_check $check) =>
            [$check->emit((int) $c->course->id, 'a', null, null, $fixable)];
        course_auditor::audit($course->id, true, ['fakecheck']);
        $findings = finding_store::get_course_findings((int) $course->id);
        return [$course, reset($findings)];
    }

    /**
     * Preview, apply and undo.
     */
    public function test_preview_apply_undo(): void {
        global $DB;
        [$course, $finding] = $this->create_finding();
        $this->assertTrue($finding->fixable);

        $preview = fixer::preview($finding->id);
        $this->assertSame(['showreports' => 0], $preview->before);
        $this->assertSame(['showreports' => 1], $preview->after);
        $this->assertEquals(
            0,
            $DB->get_field('course', 'showreports', ['id' => $course->id]),
            'Preview must not change anything'
        );

        $result = fixer::apply($finding->id, [], 5);
        $this->assertTrue($result->success);
        $this->assertEquals(1, $DB->get_field('course', 'showreports', ['id' => $course->id]));
        $this->assertSame(finding::STATUS_FIXED, finding_store::get($finding->id)->status);

        $log = fix_log::get($result->fixlogid);
        $this->assertEquals(5, $log->userid);
        $this->assertSame('showreports', $log->fixtype);
        $this->assertSame(['showreports' => 0], json_decode($log->valuebefore, true));
        $this->assertSame(['showreports' => 1], json_decode($log->valueafter, true));

        // A fixed finding cannot be fixed again.
        try {
            fixer::apply($finding->id, [], 5);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:findingnotopen', $e->errorcode);
        }

        $undo = fixer::undo($result->fixlogid, 6);
        $this->assertTrue($undo->success);
        $this->assertEquals(0, $DB->get_field('course', 'showreports', ['id' => $course->id]));
        $this->assertSame(finding::STATUS_OPEN, finding_store::get($finding->id)->status);
        $log = fix_log::get($result->fixlogid);
        $this->assertEquals(1, $log->undone);
        $this->assertEquals(6, $log->undoneby);

        $this->expectException(\moodle_exception::class);
        fixer::undo($result->fixlogid, 6);
    }

    /**
     * Parameters are validated by the check and passed to the change.
     */
    public function test_params(): void {
        global $DB;
        [$course, $finding] = $this->create_finding();
        fixer::apply($finding->id, ['value' => 0], 5);
        $this->assertEquals(0, $DB->get_field('course', 'showreports', ['id' => $course->id]));

        [, $finding2] = $this->create_finding();
        $this->expectException(\invalid_parameter_exception::class);
        fixer::preview($finding2->id, ['value' => 7]);
    }

    /**
     * Findings that cannot be fixed are refused.
     */
    public function test_not_fixable(): void {
        [, $finding] = $this->create_finding(false);
        $this->expectException(\moodle_exception::class);
        fixer::apply($finding->id, [], 5);
    }
}
