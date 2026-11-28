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

namespace local_chronifycompliance\output;

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(course_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(finding_exporter::class)]
/**
 * Tests of the course report: tabs, finding cards by permission, and the fix history.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\course_report
 * @covers \local_chronifycompliance\output\finding_exporter
 */
final class course_report_test extends \advanced_testcase {
    /**
     * Export a tab.
     *
     * @param \stdClass $course
     * @param string $tab
     * @return array
     */
    private function export(\stdClass $course, string $tab): array {
        global $PAGE;
        $PAGE->set_url('/local/chronifycompliance/course.php', ['id' => $course->id]);
        $PAGE->set_context(\context_course::instance($course->id));
        return (new course_report($course, $tab))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A user with the given plugin capabilities in the course.
     *
     * @param \stdClass $course
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_with(\stdClass $course, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $role = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability('local/chronifycompliance:' . $capability, CAP_ALLOW, $role, \context_course::instance($course->id));
        }
        role_assign($role, $user->id, \context_course::instance($course->id));
        return $user;
    }

    /**
     * Teachers can view and ignore; only users who may apply fixes get Preview fix and Undo.
     */
    public function test_cards_by_permission(): void {
        $this->resetAfterTest();
        $courses = seeder::seed(['nopassgrade', 'weakcondition']);
        foreach ($courses as $courseid) {
            course_auditor::audit($courseid, true);
        }
        $course = get_course($courses['nopassgrade']);

        $this->setUser($this->user_with($course, ['viewcoursereport', 'ignorefinding']));
        $cards = array_merge(...array_column($this->export($course, 'problems')['groups'], 'findings'));
        $this->assertNotEmpty($cards);
        foreach ($cards as $card) {
            $this->assertFalse($card['mainfix'], 'Teachers do not apply fixes');
            $this->assertTrue($card['canignore']);
        }
        $nopass = array_column($cards, null, 'checkid')['nopassgrade'];
        $this->assertTrue($nopass['mainsetup'], 'Without the fix capability the main action is the activity settings');
        $this->assertFalse($nopass['menuedit']);

        $this->setUser($this->user_with($course, ['viewcoursereport', 'ignorefinding', 'applyfix']));
        $cards = array_merge(...array_column($this->export($course, 'problems')['groups'], 'findings'));
        $nopass = array_column($cards, null, 'checkid')['nopassgrade'];
        $this->assertTrue($nopass['mainfix']);
        $this->assertTrue($nopass['menuedit'], 'Settings move to the menu');

        // Viewers without ignore get neither.
        $this->setUser($this->user_with($course, ['viewcoursereport']));
        foreach (array_merge(...array_column($this->export($course, 'problems')['groups'], 'findings')) as $card) {
            $this->assertFalse($card['canignore']);
            $this->assertFalse($card['mainfix']);
        }
    }

    /**
     * Tabs count problems, ignored problems and fixes; the Fixed tab lists the fix history with Undo.
     */
    public function test_tabs_and_history(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $courses = seeder::seed(['requireddeleted', 'weakcondition']);
        foreach ($courses as $courseid) {
            course_auditor::audit($courseid, true);
        }
        $course = get_course($courses['requireddeleted']);
        $open = finding_store::get_course_findings((int) $course->id, [finding::STATUS_OPEN]);
        $fixable = array_values(array_filter($open, fn($f) => $f->fixable));
        $this->assertNotEmpty($fixable);

        $data = $this->export($course, 'problems');
        $this->assertSame(['problems', 'ignored', 'fixed', 'links'], array_column($data['tabs'], 'key'));
        $this->assertStringContainsString('(' . count($open) . ')', $data['tabs'][0]['label']);
        $this->assertStringContainsString('(0)', $data['tabs'][2]['label']);

        $result = fixer::apply((int) $fixable[0]->id, [], 2);
        $this->assertTrue($result->success);
        $data = $this->export($course, 'fixed');
        $this->assertTrue($data['isfixed']);
        $this->assertCount(1, $data['history']);
        $this->assertTrue($data['history'][0]['canundo']);
        $this->assertNull($data['history'][0]['undone']);
        $this->assertSame([], $data['groups'], 'Only the open tab is built');

        fixer::undo($result->fixlogid, 2);
        $data = $this->export($course, 'fixed');
        $this->assertFalse($data['history'][0]['canundo']);
        $this->assertNotNull($data['history'][0]['undone']);

        // Ignored tab.
        $finding = reset($open);
        finding_store::ignore((int) $finding->id, 'Not needed', 2);
        $data = $this->export($course, 'ignored');
        $this->assertCount(1, $data['ignored']);
        $this->assertTrue($data['ignored'][0]['mainreopen']);
        $this->assertStringContainsString('(1)', $data['tabs'][1]['label']);

        // Unknown tabs fall back to Problems.
        $this->assertTrue($this->export($course, 'other')['isproblems']);
    }
}
