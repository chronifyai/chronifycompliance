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

namespace local_chronifycompliance\external;

use core_external\external_api;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\local\finding_store;

/**
 * Tests of the web service functions used by the UI.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\external\audit_course
 * @covers \local_chronifycompliance\external\preview_fix
 * @covers \local_chronifycompliance\external\apply_fix
 * @covers \local_chronifycompliance\external\undo_fix
 * @covers \local_chronifycompliance\external\ignore_finding
 * @covers \local_chronifycompliance\external\get_scan_status
 * @covers \local_chronifycompliance\local\access
 */
#[\PHPUnit\Framework\Attributes\CoversClass(audit_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(preview_fix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(apply_fix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo_fix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ignore_finding::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_scan_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\local\access::class)]
final class external_test extends \core_external\tests\externallib_testcase {
    /** @var \stdClass Course with a nopassgrade finding. */
    private \stdClass $course;
    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Manager in the course. */
    private \stdClass $manager;
    /** @var \stdClass Student. */
    private \stdClass $student;

    /**
     * Create a course from the nopassgrade demo scenario, and users.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        check_registry::reset();
        $this->setAdminUser();
        \local_chronifycompliance\tests\role_grants::grant();
        $generator = $this->getDataGenerator();
        $this->course = (new \local_chronifycompliance\tests\demo\scenario\nopassgrade())->create(
            $generator,
            (int) $generator->create_category()->id
        );
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->manager = $generator->create_and_enrol($this->course, 'manager');
        $this->student = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * Audit as a teacher, returning the nopassgrade finding id.
     *
     * @return int
     */
    private function audit_as_teacher(): int {
        $this->setUser($this->teacher);
        // The service queues the check; the task runs it, then the service returns the stored result.
        audit_course::execute((int) $this->course->id, true);
        ob_start();
        $this->runAdhocTasks(\local_chronifycompliance\task\check_course::class);
        ob_end_clean();
        $this->setUser($this->teacher);
        $result = external_api::clean_returnvalue(
            audit_course::execute_returns(),
            audit_course::execute((int) $this->course->id)
        );
        $this->assertSame(1, $result['critical']);
        $ids = array_column(array_filter($result['findings'], fn($f) => $f['checkid'] === 'nopassgrade'), 'id');
        return (int) reset($ids);
    }

    /**
     * Students cannot see the report.
     */
    public function test_audit_course_requires_capability(): void {
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        audit_course::execute((int) $this->course->id, true);
    }

    /**
     * Teachers can preview but not apply; managers apply and undo.
     */
    public function test_preview_apply_undo(): void {
        global $DB;
        $findingid = $this->audit_as_teacher();

        // Teachers do not have applyfix by default.
        try {
            preview_fix::execute($findingid, []);
            $this->fail('Teacher must not preview fixes');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(
                get_string('chronifycompliance:applyfix', 'local_chronifycompliance'),
                $e->getMessage()
            );
        }

        $this->setUser($this->manager);
        $preview = external_api::clean_returnvalue(preview_fix::execute_returns(), preview_fix::execute($findingid, []));
        $this->assertSame('gradepass', $preview['parameters'][0]['name']);
        $this->assertSame('50', $preview['parameters'][0]['value'], 'Default is half the maximum grade');
        $this->assertTrue($preview['canapply']);

        $invalid = external_api::clean_returnvalue(
            preview_fix::execute_returns(),
            preview_fix::execute($findingid, [['name' => 'gradepass', 'value' => '500']])
        );
        $this->assertNotSame('', $invalid['error']);
        $this->assertFalse($invalid['canapply']);

        $applied = external_api::clean_returnvalue(
            apply_fix::execute_returns(),
            apply_fix::execute($findingid, [['name' => 'gradepass', 'value' => '65'], ['name' => 'unknown', 'value' => 'x']])
        );
        $this->assertTrue($applied['success']);
        $this->assertEquals(65, $DB->get_field('grade_items', 'gradepass', ['courseid' => $this->course->id,
            'itemmodule' => 'assign']));

        $undone = external_api::clean_returnvalue(undo_fix::execute_returns(), undo_fix::execute($applied['fixlogid']));
        $this->assertTrue($undone['success']);
        $this->assertEquals(0, $DB->get_field('grade_items', 'gradepass', ['courseid' => $this->course->id,
            'itemmodule' => 'assign']));

        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        undo_fix::execute($applied['fixlogid']);
    }

    /**
     * Teachers ignore and reopen; students cannot.
     */
    public function test_ignore_and_reopen(): void {
        $findingid = $this->audit_as_teacher();
        $result = external_api::clean_returnvalue(
            ignore_finding::execute_returns(),
            ignore_finding::execute($findingid, 'Pass mark set in the gradebook next week', true)
        );
        $this->assertSame('ignored', $result['status']);
        $this->assertSame('Pass mark set in the gradebook next week', finding_store::get($findingid)->ignorereason);

        $result = ignore_finding::execute($findingid, '', false);
        $this->assertSame('open', $result['status']);

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        ignore_finding::execute($findingid, 'x', true);
    }

    /**
     * Unknown findings are refused.
     */
    public function test_invalid_finding(): void {
        $this->setUser($this->manager);
        $this->expectException(\dml_missing_record_exception::class);
        preview_fix::execute(999999, []);
    }

    /**
     * Scan status for site managers only.
     */
    public function test_scan_status(): void {
        $this->setAdminUser();
        $status = external_api::clean_returnvalue(get_scan_status::execute_returns(), get_scan_status::execute());
        $this->assertSame(0, $status['scanid']);
        \local_chronifycompliance\local\scan_manager::start_scan(2);
        $status = get_scan_status::execute();
        $this->assertSame('queued', $status['status']);

        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        get_scan_status::execute();
    }
}
