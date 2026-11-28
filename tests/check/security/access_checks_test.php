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

namespace local_chronifycompliance\check\security;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(adminmfa::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(inactiveadmins::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(passwordstrength::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(selfregistration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(guestaccess::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(riskyroles::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\security_check::class)]
/**
 * Tests of the access and account checks of the Security pack, through their demo site scenarios.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\security\adminmfa
 * @covers \local_chronifycompliance\check\security\inactiveadmins
 * @covers \local_chronifycompliance\check\security\passwordstrength
 * @covers \local_chronifycompliance\check\security\selfregistration
 * @covers \local_chronifycompliance\check\security\guestaccess
 * @covers \local_chronifycompliance\check\security\riskyroles
 * @covers \local_chronifycompliance\check\security_check
 */
final class access_checks_test extends \advanced_testcase {
    /** @var string[] Checks of this step, each with a site scenario. */
    private const CHECKS = ['adminmfa', 'guestaccess', 'inactiveadmins', 'passwordstrength', 'riskyroles', 'selfregistration'];

    /**
     * Run one check.
     *
     * @param string $id
     * @return finding[]
     */
    private function run_check(string $id): array {
        check_registry::reset();
        return check_registry::get_check($id)->run(new audit_context(get_site()));
    }

    /**
     * Data provider: the checks.
     *
     * @return array
     */
    public static function checks_provider(): array {
        return array_combine(self::CHECKS, array_map(fn($id) => [$id], self::CHECKS));
    }

    /**
     * Each check has a site scenario that fails and passes, is in the Security pack and links to a setting.
     *
     * @dataProvider checks_provider
     * @param string $id
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('checks_provider')]
    public function test_scenario_fails_and_passes(string $id): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenarios = site_scenario::get_all();
        $this->assertArrayHasKey($id, $scenarios);
        $check = check_registry::get_check($id);
        $this->assertSame(base_check::PACK_SECURITY, $check->get_pack());
        $this->assertSame(base_check::SCOPE_SITE, $check->get_scope());
        foreach (['name', 'desc'] as $key) {
            $this->assertTrue(get_string_manager()->string_exists("check:{$id}:{$key}", 'local_chronifycompliance'));
        }

        $scenarios[$id]->fail($this->getDataGenerator());
        $findings = $this->run_check($id);
        $this->assertNotEmpty($findings, "{$id} fails");
        foreach ($findings as $finding) {
            $this->assertSame((int) SITEID, $finding->courseid);
            $this->assertNotEmpty($finding->details['url'], 'Link to the setting');
            $this->assertStringNotContainsString('[[', $finding->get_message());
            if (check_registry::get_check($id) instanceof \local_chronifycompliance\check\guided_fix) {
                $this->assertTrue($finding->fixable, 'Fixed with a guided setup');
            } else {
                $this->assertFalse($finding->fixable, 'Security checks are not fixed in one click');
            }
        }

        $scenarios[$id]->pass($this->getDataGenerator());
        $this->assertSame([], $this->run_check($id), "{$id} passes");
    }

    /**
     * MFA: administrators without a set up factor are counted; a TOTP factor counts as set up.
     */
    public function test_adminmfa_counts_admins(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'tool_mfa');
        set_config('enabled', 0, 'factor_email');
        set_config('enabled', 1, 'factor_totp');
        $findings = $this->run_check('adminmfa');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('1 of 1', $findings[0]->get_message());

        $DB->insert_record('tool_mfa', ['userid' => get_admin()->id, 'factor' => 'totp', 'revoked' => 0,
            'timecreated' => time(), 'lockcounter' => 0]);
        $this->assertSame([], $this->run_check('adminmfa'));
        $DB->set_field('tool_mfa', 'revoked', 1, ['userid' => get_admin()->id]);
        $this->assertCount(1, $this->run_check('adminmfa'), 'A revoked factor does not count');
    }

    /**
     * Password policy, guest access and self registration cases.
     */
    public function test_cases(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('passwordpolicy', 1);
        set_config('minpasswordlength', 6);
        set_config('lockoutthreshold', 5);
        $this->assertSame([['case' => 'length']], array_map(fn($f) => $f->identity, $this->run_check('passwordstrength')));
        set_config('passwordpolicy', 0);
        $this->assertSame(
            [['case' => 'off']],
            array_map(fn($f) => $f->identity, $this->run_check('passwordstrength')),
            'Policy off is reported here, not by the core check'
        );

        set_config('autologinguests', 0);
        set_config('guestloginbutton', 1);
        $course = $this->getDataGenerator()->create_course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['enrol' => 'guest', 'courseid' => $course->id]);
        $findings = $this->run_check('guestaccess');
        $this->assertSame([base_check::SEVERITY_INFO, base_check::SEVERITY_INFO], array_map(fn($f) => $f->severity, $findings));

        set_config('auth', 'email');
        set_config('registerauth', 'email');
        set_config('allowemailaddresses', 'example.edu');
        $findings = $this->run_check('selfregistration');
        $this->assertSame(base_check::SEVERITY_INFO, $findings[0]->severity);
        $this->assertStringContainsString('example.edu', $findings[0]->get_message());
    }
}
