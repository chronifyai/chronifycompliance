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

namespace local_chronifycompliance\check;

use core\check\result;
use local_chronifycompliance\check\security\coresecurity;
use local_chronifycompliance\check\site\coreperformance;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\output\finding_exporter;

#[\PHPUnit\Framework\Attributes\CoversClass(core_import_check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(coresecurity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(coreperformance::class)]
/**
 * Tests of the import of Moodle's own Check API results.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\core_import_check
 * @covers \local_chronifycompliance\check\security\coresecurity
 * @covers \local_chronifycompliance\check\site\coreperformance
 */
final class core_import_check_test extends \advanced_testcase {
    /**
     * Every core result that is not OK becomes one finding, with the mapped severity and the core link.
     */
    public function test_results_become_findings(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Make two core security checks fail: no password policy (read by passwordstrength instead), and the
        // "no authentication" plugin on.
        set_config('passwordpolicy', 0);
        set_config('auth', 'none');
        $CFG->auth = 'none';

        foreach ([new coresecurity(), new coreperformance()] as $check) {
            $core = $check->get_core_checks();
            $this->assertNotEmpty($core);
            foreach ($core as $ref => $corecheck) {
                $this->assertNotSame('local_chronifycompliance', $corecheck->get_component(), 'Own checks are skipped');
                $this->assertNotContains($ref, core_import_check::EXCLUDED);
            }
            $expected = [];
            foreach ($core as $ref => $corecheck) {
                $status = $corecheck->get_result()->get_status();
                if (isset(core_import_check::SEVERITIES[$status])) {
                    $expected[$ref] = core_import_check::SEVERITIES[$status];
                }
            }
            $findings = $check->run(new audit_context(get_site()));
            $actual = [];
            foreach ($findings as $finding) {
                $actual[$finding->details['ref']] = $finding->severity;
                $this->assertSame((int) SITEID, $finding->courseid);
                $this->assertStringNotContainsString('<', $finding->get_message(), 'Plain text message');
            }
            $this->assertEquals($expected, $actual, $check->get_id());
        }

        $security = (new coresecurity())->run(new audit_context(get_site()));
        $byref = [];
        foreach ($security as $finding) {
            $byref[$finding->details['ref']] = $finding;
        }
        $this->assertArrayNotHasKey('core_passwordpolicy', $byref, 'Covered by passwordstrength');
        $this->assertArrayHasKey('auth_none_noauth', $byref);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $byref['auth_none_noauth']->severity);
        $this->assertNotEmpty($byref['auth_none_noauth']->details['url'], 'Link to the core setting');

        // Stored by the site audit, with the setting as the card's Set up link.
        site_auditor::audit(true);
        $stored = array_filter(
            finding_store::get_course_findings((int) SITEID),
            fn($f) => $f->checkid === 'coresecurity' && $f->details['ref'] === 'auth_none_noauth'
        );
        $this->assertCount(1, $stored);
        $card = finding_exporter::export(reset($stored), null, ['canfix' => true, 'canignore' => true]);
        $this->assertTrue($card['mainsetup']);
        $this->assertSame($byref['auth_none_noauth']->details['url'], $card['editurl']);

        // Fixing the setting resolves the finding at the next audit.
        set_config('auth', 'email');
        $CFG->auth = 'email';
        site_auditor::audit(true);
        $open = array_filter(
            finding_store::get_course_findings((int) SITEID),
            fn($f) => $f->checkid === 'coresecurity' && $f->details['ref'] === 'auth_none_noauth'
        );
        $this->assertCount(0, $open);
    }

    /**
     * The checks are not offered back to core's System status page, which would run them in a loop.
     */
    public function test_not_offered_to_core(): void {
        global $CFG;
        require_once($CFG->dirroot . "/local/chronifycompliance/lib.php");
        $this->resetAfterTest();
        foreach (local_chronifycompliance_status_checks() as $check) {
            $this->assertNotContains($check->get_id(), ['coresecurity', 'coreperformance']);
        }
        $this->assertSame(base_check::PACK_SECURITY, (new coresecurity())->get_pack());
        $this->assertSame(base_check::PACK_SITE, (new coreperformance())->get_pack());
        $this->assertSame(base_check::SEVERITY_CRITICAL, core_import_check::SEVERITIES[result::ERROR]);
    }
}
