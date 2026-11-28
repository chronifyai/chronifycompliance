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
use local_chronifycompliance\scorm\inspector;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(scorm_panel::class)]
/**
 * Tests of the SCORM analysis page: the recommended settings action follows the fix capability.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\scorm_panel
 */
final class scorm_panel_test extends \advanced_testcase {
    /**
     * Preview recommended settings points at the fixable finding of the activity, only for users who may fix.
     */
    public function test_apply_action(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $courseid = seeder::seed(['scormstatusmismatch'])['scormstatusmismatch'];
        course_auditor::audit($courseid, true);
        $cm = array_values(get_fast_modinfo($courseid)->get_instances_of('scorm'))[0];
        $PAGE->set_url('/local/chronifycompliance/scorm.php', ['cmid' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $output = $PAGE->get_renderer('core');

        $data = (new scorm_panel($cm, inspector::inspect($cm)))->export_for_template($output);
        $this->assertNotEmpty($data['findings']);
        $this->assertNotNull($data['applyid']);
        $this->assertContains($data['applyid'], array_column($data['findings'], 'id'));
        $html = $PAGE->get_renderer('local_chronifycompliance')->scorm_panel($cm, inspector::inspect($cm));
        $this->assertStringContainsString('data-region="package-card"', $html);
        $this->assertStringContainsString('data-region="evidence-card"', $html);
        $this->assertStringNotContainsString('[[', $html);

        $viewer = $this->getDataGenerator()->create_and_enrol(get_course($courseid), 'editingteacher');
        $this->setUser($viewer);
        $this->assertNull((new scorm_panel($cm, inspector::inspect($cm)))->export_for_template($output)['applyid']);
    }
}
