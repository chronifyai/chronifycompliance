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

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\guided_fix;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\output\finding_exporter;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(passwordstrength::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(adminmfa::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\setting_fix::class)]
/**
 * Tests of the guided fixes: password policy and multi-factor authentication.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\security\passwordstrength
 * @covers \local_chronifycompliance\check\security\adminmfa
 * @covers \local_chronifycompliance\check\setting_fix
 */
final class guided_fix_test extends \advanced_testcase {
    /**
     * Fail the check's scenario, run only that check and return its first open finding.
     *
     * @param string $id
     * @return finding
     */
    private function open_finding(string $id): finding {
        $this->resetAfterTest();
        $this->setAdminUser();
        site_scenario::get_all()[$id]->fail($this->getDataGenerator());
        foreach (array_keys(check_registry::get_checks([base_check::SCOPE_SITE], true)) as $other) {
            set_config('enabled_' . $other, (int) ($other === $id), 'local_chronifycompliance');
        }
        check_registry::reset();
        site_auditor::audit(true);
        $open = array_values(array_filter(
            finding_store::get_course_findings((int) SITEID),
            fn($f) => $f->checkid === $id && $f->fixable
        ));
        $this->assertNotEmpty($open, "{$id} has a fixable finding");
        return $open[0];
    }

    /**
     * Default answers of a wizard.
     *
     * @param guided_fix $check
     * @param finding $finding
     * @return array
     */
    private function defaults(guided_fix $check, finding $finding): array {
        return array_column($check->get_fix_parameters($finding), 'default', 'name');
    }

    /**
     * Data provider: the guided checks.
     *
     * @return array
     */
    public static function guided_provider(): array {
        return ['passwordstrength' => ['passwordstrength'], 'adminmfa' => ['adminmfa']];
    }

    /**
     * Steps name only parameters the check has, and the card opens the wizard.
     *
     * @dataProvider guided_provider
     * @param string $id
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guided_provider')]
    public function test_steps_and_card(string $id): void {
        $finding = $this->open_finding($id);
        $check = check_registry::get_check($id);
        $this->assertInstanceOf(guided_fix::class, $check);
        $names = array_column($check->get_fix_parameters($finding), 'name');
        foreach ($check->get_guided_steps($finding) as $step) {
            $this->assertNotEmpty($step['fields']);
            $this->assertSame([], array_diff($step['fields'], $names), "{$id} step {$step['key']}");
            get_string($step['title'], 'local_chronifycompliance');
            get_string($step['intro'], 'local_chronifycompliance');
        }
        $card = finding_exporter::export($finding, null, ['canfix' => true, 'canignore' => true]);
        $this->assertTrue($card['mainguided']);
        $this->assertFalse($card['mainfix']);
        $this->assertStringContainsString('guided.php?id=' . $finding->id, $card['guidedurl']);
        $card = finding_exporter::export($finding, null, ['canfix' => false, 'canignore' => true]);
        $this->assertFalse($card['mainguided'], 'Viewers without the fix capability see no wizard');
    }

    /**
     * Password policy: the review lists every change, apply sets them all, undo restores them all.
     */
    public function test_password_policy(): void {
        $finding = $this->open_finding('passwordstrength');
        $check = check_registry::get_check('passwordstrength');
        $answers = ['minpasswordlength' => 14, 'lockoutthreshold' => 5] + $this->defaults($check, $finding);
        $this->assertSame(12, (int) $this->defaults($check, $finding)['minpasswordlength'], 'Suggests 12');

        $preview = fixer::preview((int) $finding->id, $answers);
        $labels = array_column($preview->rows, 'after', 'label');
        $this->assertSame('14', $labels[get_string('guided:password:minpasswordlength', 'local_chronifycompliance')]);
        $this->assertSame('5', $labels[get_string('guided:password:lockoutthreshold', 'local_chronifycompliance')]);
        $this->assertSame('6', (string) get_config('core', 'minpasswordlength'), 'The preview writes nothing');
        $this->assertContains(get_string('guided:password:warning', 'local_chronifycompliance'), $preview->warnings);

        $result = fixer::apply((int) $finding->id, $answers, (int) get_admin()->id);
        $this->assertTrue($result->success);
        $this->assertSame('14', (string) get_config('core', 'minpasswordlength'));
        $this->assertSame('5', (string) get_config('core', 'lockoutthreshold'));
        $this->assertSame('1', (string) get_config('core', 'passwordpolicy'));
        check_registry::reset();
        $this->assertSame([], check_registry::get_check('passwordstrength')->run(
            new \local_chronifycompliance\check\audit_context(get_site())
        ), 'Resolved');

        $this->assertTrue(fixer::undo($result->fixlogid, (int) get_admin()->id)->success);
        $this->assertSame('6', (string) get_config('core', 'minpasswordlength'));
        $this->assertSame('0', (string) get_config('core', 'lockoutthreshold'));
    }

    /**
     * Password policy: answers out of range are refused before anything changes.
     */
    public function test_password_range(): void {
        $finding = $this->open_finding('passwordstrength');
        $check = check_registry::get_check('passwordstrength');
        $this->expectException(\moodle_exception::class);
        fixer::preview((int) $finding->id, ['minpasswordlength' => 4] + $this->defaults($check, $finding));
    }

    /**
     * Policy off: the check says so and the wizard turns it on.
     */
    public function test_password_policy_off(): void {
        $this->resetAfterTest();
        set_config('passwordpolicy', 0);
        set_config('lockoutthreshold', 10);
        $findings = check_registry::get_check('passwordstrength')->run(
            new \local_chronifycompliance\check\audit_context(get_site())
        );
        $this->assertCount(1, $findings);
        $this->assertSame(
            get_string('check:passwordstrength:messageoff', 'local_chronifycompliance'),
            $findings[0]->get_message()
        );
    }

    /**
     * MFA: admins only with a grace period enables the chosen factors, the admin factor and the grace factor,
     * and undo turns them all off again.
     */
    public function test_mfa_admins_only(): void {
        $finding = $this->open_finding('adminmfa');
        $check = check_registry::get_check('adminmfa');
        $answers = ['factor_totp' => 1, 'factor_webauthn' => 0, 'factor_email' => 1, 'adminsonly' => 1, 'grace' => 1,
            'gracedays' => 5];

        $preview = fixer::preview((int) $finding->id, $answers);
        $this->assertNotEmpty($preview->rows);
        $this->assertEmpty(get_config('tool_mfa', 'enabled'), 'The preview writes nothing');

        $result = fixer::apply((int) $finding->id, $answers, (int) get_admin()->id);
        $this->assertTrue($result->success);
        $this->assertSame('1', (string) get_config('tool_mfa', 'enabled'));
        foreach (['factor_totp', 'factor_email', 'factor_admin', 'factor_grace'] as $plugin) {
            $this->assertSame('1', (string) get_config($plugin, 'enabled'), $plugin);
        }
        $this->assertEmpty(get_config('factor_webauthn', 'enabled'));
        $this->assertSame((string) (5 * DAYSECS), (string) get_config('factor_grace', 'graceperiod'));
        $order = explode(',', (string) get_config('tool_mfa', 'factor_order'));
        $this->assertContains('totp', $order, 'Factor order updated, as the MFA settings page does');

        $this->assertTrue(fixer::undo($result->fixlogid, (int) get_admin()->id)->success);
        $this->assertEmpty(get_config('tool_mfa', 'enabled'));
        $this->assertEmpty(get_config('factor_totp', 'enabled'));
    }

    /**
     * MFA: everyone, no grace period: warns about both, and needs at least one factor.
     */
    public function test_mfa_warnings_and_no_factor(): void {
        $finding = $this->open_finding('adminmfa');
        $answers = ['factor_totp' => 1, 'factor_webauthn' => 0, 'factor_email' => 0, 'adminsonly' => 0, 'grace' => 0,
            'gracedays' => 7];
        $preview = fixer::preview((int) $finding->id, $answers);
        $this->assertContains(get_string('guided:mfa:warningeveryone', 'local_chronifycompliance'), $preview->warnings);
        $this->assertContains(get_string('guided:mfa:warningnograce', 'local_chronifycompliance'), $preview->warnings);
        $this->assertNotContains('factor_admin', array_column($preview->after['settings'], 'plugin'));

        $this->expectException(\moodle_exception::class);
        fixer::preview((int) $finding->id, ['factor_totp' => 0] + $answers);
    }
}
