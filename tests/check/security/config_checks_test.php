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
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(debugdisplay::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sessioncookies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(webservices::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(wstokens::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(exposedfiles::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\check\security_check::class)]
/**
 * Tests of the configuration and web service checks, and of the one click setting fixes.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\security\debugdisplay
 * @covers \local_chronifycompliance\check\security\sessioncookies
 * @covers \local_chronifycompliance\check\security\webservices
 * @covers \local_chronifycompliance\check\security\wstokens
 * @covers \local_chronifycompliance\check\security\exposedfiles
 * @covers \local_chronifycompliance\check\security_check
 */
final class config_checks_test extends \advanced_testcase {
    /** @var string[] Checks of this step. */
    private const CHECKS = ['debugdisplay', 'exposedfiles', 'sessioncookies', 'webservices', 'wstokens'];

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
     * Each check fails and passes with its site scenario, in the Security pack, with a link to the setting.
     *
     * @dataProvider checks_provider
     * @param string $id
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('checks_provider')]
    public function test_scenario_fails_and_passes(string $id): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = site_scenario::get_all()[$id];
        $this->assertSame(base_check::PACK_SECURITY, check_registry::get_check($id)->get_pack());
        $scenario->fail($this->getDataGenerator());
        $findings = $this->run_check($id);
        $this->assertNotEmpty($findings, "{$id} fails");
        foreach ($findings as $finding) {
            $this->assertNotEmpty($finding->details['url']);
            $this->assertStringNotContainsString('[[', $finding->get_message());
        }
        $scenario->pass($this->getDataGenerator());
        $this->assertSame([], $this->run_check($id), "{$id} passes");
    }

    /**
     * Data provider: the one click fixes, with the setting and the value they set.
     *
     * @return array
     */
    public static function fixes_provider(): array {
        return [
            'debugdisplay' => ['debugdisplay', 'debugdisplay', '0'],
            'sessioncookies' => ['sessioncookies', 'cookiehttponly', '1'],
            'webservices' => ['webservices', 'enablewebservices', '0'],
        ];
    }

    /**
     * The fix previews the change, changes only that setting, logs it, resolves the finding, and undo puts it back.
     *
     * @dataProvider fixes_provider
     * @param string $id
     * @param string $setting
     * @param string $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixes_provider')]
    public function test_fix_and_undo(string $id, string $setting, string $value): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        site_scenario::get_all()[$id]->fail($this->getDataGenerator());
        $before = (string) get_config('core', $setting);
        foreach (check_registry::get_checks([base_check::SCOPE_SITE], true) as $other => $check) {
            if ($other !== $id) {
                set_config('enabled_' . $other, 0, 'local_chronifycompliance');
            }
        }
        check_registry::reset();
        site_auditor::audit(true);
        $open = finding_store::get_course_findings((int) SITEID);
        $fixable = array_values(array_filter($open, fn($f) => $f->checkid === $id && $f->fixable));
        $this->assertCount(1, $fixable);

        $preview = fixer::preview((int) $fixable[0]->id);
        $this->assertSame($value, $preview->after['settings'][0]['value']);
        $this->assertSame($before, (string) get_config('core', $setting), 'The preview writes nothing');
        $this->assertNotEmpty($preview->warnings);

        $logs = $DB->count_records('config_log', ['name' => $setting]);
        $result = fixer::apply((int) $fixable[0]->id, [], (int) get_admin()->id);
        $this->assertTrue($result->success);
        $this->assertSame($value, (string) get_config('core', $setting));
        $this->assertSame($logs + 1, $DB->count_records('config_log', ['name' => $setting]), 'Logged in Config changes');
        site_auditor::audit(true);
        $this->assertSame([], array_filter($this->run_check($id), fn($f) => $f->fixable), 'Resolved');

        $this->assertTrue(fixer::undo($result->fixlogid, (int) get_admin()->id)->success);
        $this->assertSame($before, (string) get_config('core', $setting));

        // Undo is refused when the setting changed after the fix.
        $result = fixer::apply((int) $fixable[0]->id, [], (int) get_admin()->id);
        set_config($setting, 'changed');
        $this->expectException(\moodle_exception::class);
        fixer::undo($result->fixlogid, (int) get_admin()->id);
    }

    /**
     * Token and debug cases.
     */
    public function test_cases(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Forced in config.php (as on this test site): reported, but not fixable from here.
        $CFG->config_php_settings['debugdisplay'] = 1;
        $CFG->debugdisplay = 1;
        set_config('debug', DEBUG_DEVELOPER);
        $finding = $this->run_check('debugdisplay')[0];
        $this->assertSame(base_check::SEVERITY_CRITICAL, $finding->severity);
        $this->assertFalse($finding->fixable);

        site_scenario::get_all()['wstokens']->fail($this->getDataGenerator());
        $cases = array_map(fn($f) => $f->identity['case'], $this->run_check('wstokens'));
        sort($cases);
        $this->assertSame(['admin', 'noexpiry'], $cases);
        $DB->set_field('external_tokens', 'validuntil', time() + DAYSECS, []);
        $this->assertSame(['admin'], array_map(fn($f) => $f->identity['case'], $this->run_check('wstokens')));

        // Config copies in the Moodle folder are found by name; the real config files are not.
        $this->assertContains('config.php.*', exposedfiles::CONFIG_COPIES);
        $this->assertFalse(fnmatch('config.php.*', 'config-dist.php'));
        $this->assertTrue(fnmatch('config.php.*', 'config.php.bak'));
        $this->assertSame([], $this->run_check('exposedfiles'));
    }
}
