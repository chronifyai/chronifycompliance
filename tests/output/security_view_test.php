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
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(security_view::class)]
/**
 * Tests of the Security view.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\security_view
 */
final class security_view_test extends \advanced_testcase {
    /**
     * Export the view.
     *
     * @param string $filter
     * @return array
     */
    private function export(string $filter = ''): array {
        global $PAGE;
        $PAGE->set_url('/local/chronifycompliance/security.php');
        $PAGE->set_context(\context_system::instance());
        return (new security_view($filter))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Put every security check in its passing state, then two in their failing state, and run the site checks.
     */
    private function prepare(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenarios = site_scenario::get_all();
        set_config('enabled_coresecurity', 0, 'local_chronifycompliance');
        foreach (check_registry::get_checks([base_check::SCOPE_SITE], true, [base_check::PACK_SECURITY]) as $id => $check) {
            if (isset($scenarios[$id])) {
                $scenarios[$id]->pass($this->getDataGenerator());
            }
        }
        $scenarios['adminmfa']->fail($this->getDataGenerator());
        $scenarios['webservices']->fail($this->getDataGenerator());
        check_registry::reset();
        site_auditor::audit(true);
    }

    /**
     * Groups by area with one action per row, tiles, and the passed checks.
     */
    public function test_groups_tiles_and_passed(): void {
        $this->prepare();
        $data = $this->export();
        $groups = array_column($data['groups'], null, 'group');
        $this->assertSame(['access', 'config'], array_keys($groups));
        $this->assertTrue($groups['access']['open'], 'A group with a critical problem is open');
        $this->assertFalse($groups['config']['open'], 'Info only: closed');
        global $PAGE;
        $opened = (new security_view('', 'config'))->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue(array_column($opened['groups'], 'open', 'group')['config'], 'Opened by the link');
        $this->assertSame('adminmfa', $groups['access']['findings'][0]['checkid']);
        $this->assertTrue($groups['access']['findings'][0]['mainguided'], 'Fixed with a guided setup');
        $webservices = $groups['config']['findings'][0];
        $this->assertSame('webservices', $webservices['checkid']);
        $this->assertTrue($webservices['mainfix'], 'One click fix');

        $tiles = array_column($data['tiles'], 'value', 'group');
        $this->assertSame(1, $tiles['critical']);
        $this->assertSame(0, $tiles['warning']);
        $passed = array_column($data['passed'], 'checkid');
        $this->assertNotContains('adminmfa', $passed);
        $this->assertNotContains('webservices', $passed);
        $this->assertContains('riskyroles', $passed);
        $this->assertSame(count($passed), $tiles['healthy']);
        $this->assertSame(
            count(check_registry::get_checks([base_check::SCOPE_SITE], true, [base_check::PACK_SECURITY])) - 2,
            count($passed)
        );

        $html = $GLOBALS['PAGE']->get_renderer('core')->render(new security_view());
        $this->assertStringContainsString('data-group="passed"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Tile filters, ignored findings, and Completion or SCORM findings never appear.
     */
    public function test_filters_and_ignored(): void {
        global $DB;
        $this->prepare();
        $critical = $this->export('critical');
        $this->assertSame(['access'], array_column($critical['groups'], 'group'));
        $this->assertFalse($critical['showpassed']);
        $passed = $this->export('passed');
        $this->assertSame([], $passed['groups']);
        $this->assertTrue($passed['passedopen']);
        $this->assertNull($this->export("nonsense")["clearurl"], "Unknown filters show everything");

        $finding = array_values(array_filter(
            finding_store::get_course_findings((int) SITEID),
            fn($f) => $f->checkid === 'webservices'
        ))[0];
        finding_store::ignore((int) $finding->id, 'Kept on for a later integration', 2);
        $data = $this->export();
        $this->assertSame(['access'], array_column($data['groups'], 'group'));
        $this->assertTrue($data['hasignored']);
        $this->assertTrue($data['ignored'][0]['mainreopen']);

        // Site findings of other packs are not security findings.
        $this->assertSame([], array_filter(
            array_merge(...array_column($data['groups'], 'findings')),
            fn($f) => in_array($f['checkid'], ['cronstale', 'completiontasks'], true)
        ));
        $this->assertSame('config', security_view::group_of('coresecurity'));
        $this->assertSame('updates', security_view::group_of('corevulnerabilities'));
        $DB->delete_records('local_chronifycompliance_audit', ['courseid' => SITEID]);
        $this->assertSame([], $this->export()['passed'], 'Nothing passed before the first check');
    }
}
