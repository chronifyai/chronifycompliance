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

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\score\health_score;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(main_dashboard::class)]
/**
 * Tests of the main dashboard: states, score card, pack tiles, trend and top actions.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\main_dashboard
 */
final class main_dashboard_test extends \advanced_testcase {
    /**
     * Prepare the page.
     *
     * @return \renderer_base
     */
    private function get_output(): \renderer_base {
        global $PAGE;
        $PAGE->set_url('/local/chronifycompliance/index.php');
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('core');
    }

    /**
     * Before the first check the dashboard asks to finish setup, then shows Scanning while a scan runs.
     */
    public function test_not_set_up_and_scanning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = $this->get_output();

        $data = (new main_dashboard())->export_for_template($output);
        $this->assertTrue($data['notsetup']);
        $this->assertFalse($data['scanning']);
        $this->assertArrayNotHasKey('score', $data);
        $html = $output->render(new main_dashboard());
        $this->assertStringContainsString('data-region="finish-setup"', $html);
        $this->assertStringNotContainsString('[[', $html);

        $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        scan_manager::start_scan(2);
        $data = (new main_dashboard())->export_for_template($output);
        $this->assertFalse($data['notsetup']);
        $this->assertTrue($data['scanning']);
        $this->assertTrue($data['cancancel']);

        set_config('paused', 1, 'local_chronifycompliance');
        $this->assertTrue((new main_dashboard())->export_for_template($output)['paused']);
    }

    /**
     * With demo courses checked: score card, a tile per pack, top actions by points, breakdown and trend.
     */
    public function test_filled_dashboard(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = $this->get_output();
        $courses = seeder::seed(['nopassgrade', 'nocriteria', 'weakcondition', 'healthy']);
        foreach ($courses as $courseid) {
            course_auditor::audit($courseid, true);
        }

        $data = (new main_dashboard())->export_for_template($output);
        $this->assertFalse($data['notsetup']);
        $score = health_score::site();
        $this->assertSame($score->overall, $data['score']['value']);
        $this->assertNull($data['score']['change'], 'No snapshot from last week');

        $this->assertSame(base_check::get_packs(), array_column($data['packs'], 'pack'));
        $tiles = array_column($data['packs'], null, 'pack');
        $this->assertTrue($tiles[base_check::PACK_SECURITY]['hasscore'], 'Moodle security checks score the Security pack');
        $this->assertSame('critical', $tiles[base_check::PACK_COMPLETION]['severity']);
        $this->assertNotNull($tiles[base_check::PACK_COMPLETION]['url']);

        $this->assertNotEmpty($data['actions']);
        $this->assertLessThanOrEqual(main_dashboard::ACTIONS, count($data['actions']));
        $points = health_score::site_breakdown();
        $this->assertSame(array_key_first($points), $data['actions'][0]['checkid'], 'Largest gain first');
        $this->assertSame('critical', $data['actions'][0]['severity']);
        foreach ($data['actions'] as $action) {
            $this->assertTrue($action['hasmain']);
        }
        $this->assertNotEmpty($data['breakdown']);
        $this->assertFalse($data['trend']['haschart']);

        // A fixable problem in one course gets Preview fix; others link to the course or the course list.
        $nopass = array_column($data['actions'], null, 'checkid')['nopassgrade'] ?? null;
        $this->assertNotNull($nopass);
        $this->assertNotNull($nopass['fixid']);

        // Two snapshots: trend chart and change since last week.
        $DB->insert_records(health_score::TABLE, [
            ['timecreated' => time() - 14 * DAYSECS, 'pack' => health_score::OVERALL, 'score' => 60, 'critical' => 0,
                'warning' => 0, 'info' => 0],
            ['timecreated' => time() - 7 * DAYSECS, 'pack' => health_score::OVERALL, 'score' => $score->overall - 3,
                'critical' => 0, 'warning' => 0, 'info' => 0],
        ]);
        $data = (new main_dashboard())->export_for_template($output);
        $this->assertTrue($data['trend']['haschart']);
        $this->assertSame(get_string('dashboard:score:up', 'local_chronifycompliance', 3), $data['score']['change']);

        $html = $output->render(new main_dashboard());
        $this->assertStringContainsString('data-region="top-actions"', $html);
        $this->assertStringContainsString('data-region="invitation"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Once every problem is fixed or ignored there are no actions.
     */
    public function test_no_actions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (seeder::seed(['healthy']) as $courseid) {
            course_auditor::audit($courseid, true);
        }
        $DB->set_field('local_chronifycompliance_finding', 'status', finding::STATUS_IGNORED, []);
        $data = (new main_dashboard())->export_for_template($this->get_output());
        $this->assertFalse($data['hasactions']);
    }
}
