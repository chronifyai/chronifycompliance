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

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\external\apply_bulk_fix;
use local_chronifycompliance\external\preview_bulk_fix;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(bulk_fixer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(preview_bulk_fix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(apply_bulk_fix::class)]
/**
 * Tests of the bulk fix of the Completion workspace.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\fix\bulk_fixer
 * @covers \local_chronifycompliance\external\preview_bulk_fix
 * @covers \local_chronifycompliance\external\apply_bulk_fix
 */
final class bulk_fixer_test extends \core_external\tests\externallib_testcase {
    /**
     * Seed and check demo courses.
     *
     * @param string[] $ids
     * @return int[] Scenario id => course id.
     */
    private function seed(array $ids): array {
        $courses = seeder::seed($ids);
        foreach ($courses as $courseid) {
            course_auditor::audit($courseid, true);
        }
        return $courses;
    }

    /**
     * Only one click fixes are previewed and applied; problems that need a decision are counted, not fixed.
     */
    public function test_preview_and_apply(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $courses = $this->seed(['requireddeleted', 'nopassgrade', 'healthy']);
        $courseids = array_values($courses);

        $preview = preview_bulk_fix::execute($courseids);
        $preview = \core_external\external_api::clean_returnvalue(preview_bulk_fix::execute_returns(), $preview);
        $this->assertTrue($preview['canapply']);
        $this->assertSame(1, $preview['courses']);
        $this->assertGreaterThan(0, $preview['needsinput'], 'The pass grade needs a decision');
        $this->assertSame([$courses['requireddeleted']], array_values(array_unique(array_column($preview['items'], 'courseid'))));
        foreach ($preview['items'] as $item) {
            $this->assertSame('', $item['error']);
            $this->assertNotEmpty($item['rows']);
        }
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_fixlog'), 'The preview writes nothing');

        $findingids = array_column($preview['items'], 'findingid');
        $result = apply_bulk_fix::execute($courseids, $findingids);
        $result = \core_external\external_api::clean_returnvalue(apply_bulk_fix::execute_returns(), $result);
        $this->assertSame(count($findingids), $result['fixed']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(count($findingids), $result['fixlogids']);
        foreach ($findingids as $id) {
            $this->assertSame(finding::STATUS_FIXED, $DB->get_field('local_chronifycompliance_finding', 'status', ['id' => $id]));
        }
        $open = $DB->count_records('local_chronifycompliance_finding', ['courseid' => $courses['nopassgrade'],
            'status' => finding::STATUS_OPEN]);
        $this->assertGreaterThan(0, $open, 'Fixes that need a decision were not applied');

        // Each fix can be undone.
        foreach (array_reverse($result['fixlogids']) as $fixlogid) {
            $this->assertTrue(fixer::undo($fixlogid, 2)->success);
        }
        $this->assertCount(count($findingids), bulk_fixer::candidates($courseids)[0]);
    }

    /**
     * Apply only fixes findings from the preview, and only in courses where the user may fix.
     */
    public function test_only_previewed_and_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        $courses = $this->seed(['requireddeleted']);
        $courseid = $courses['requireddeleted'];
        $this->setAdminUser();
        $result = bulk_fixer::apply([$courseid], [], 2);
        $this->assertSame(0, $result['fixed'], 'Nothing previewed, nothing fixed');

        // A manager of the site without the fix capability in the course gets an empty preview.
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/chronifycompliance:viewsitereport', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->assertSame([], bulk_fixer::preview([$courseid])['items']);
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_fixlog'));
    }
}
