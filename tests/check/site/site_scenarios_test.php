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

namespace local_chronifycompliance\check\site;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\core_import_check;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(failingtasks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(caching::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(phpdbsupport::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(backupstatus::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(logretention::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(upgradeready::class)]
/**
 * Every site check has a demo site scenario that fails and passes; cases of the Site and performance checks.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\site\failingtasks
 * @covers \local_chronifycompliance\check\site\caching
 * @covers \local_chronifycompliance\check\site\phpdbsupport
 * @covers \local_chronifycompliance\check\site\backupstatus
 * @covers \local_chronifycompliance\check\site\logretention
 * @covers \local_chronifycompliance\check\site\upgradeready
 */
final class site_scenarios_test extends \advanced_testcase {
    /**
     * Back to the real versions.
     */
    protected function tearDown(): void {
        phpdbsupport::$versions = null;
        parent::tearDown();
    }

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
     * Data provider: every site check, except the two that read Moodle's own checks.
     *
     * @return array
     */
    public static function site_checks_provider(): array {
        $ids = [];
        foreach (check_registry::get_checks([base_check::SCOPE_SITE]) as $id => $check) {
            if (!$check instanceof core_import_check) {
                $ids[$id] = [$id];
            }
        }
        return $ids;
    }

    /**
     * The rule for new checks: every site check has a site scenario, and it fails and passes.
     *
     * @dataProvider site_checks_provider
     * @param string $id
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('site_checks_provider')]
    public function test_every_site_check_has_a_scenario(string $id): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenarios = site_scenario::get_all();
        $this->assertArrayHasKey($id, $scenarios, "Site scenario for {$id}");
        $scenarios[$id]->fail($this->getDataGenerator());
        $findings = $this->run_check($id);
        $this->assertNotEmpty($findings, "{$id} fails");
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString('[[', $finding->get_message());
            foreach (['name', 'desc'] as $key) {
                $this->assertTrue(get_string_manager()->string_exists("check:{$id}:{$key}", 'local_chronifycompliance'));
            }
        }
        $scenarios[$id]->pass($this->getDataGenerator());
        $this->assertSame([], $this->run_check($id), "{$id} passes");
    }

    /**
     * Support end dates: ended, ending soon, fine; unknown versions are not reported.
     */
    public function test_support_dates(): void {
        $check = new phpdbsupport();
        $now = strtotime('2026-11-28 12:00:00 UTC');
        $this->assertSame(['case' => 'php'], $check->support_finding('php', 'PHP', '8.1', '2025-12-31', $now)->identity);
        $this->assertSame(base_check::SEVERITY_WARNING, $check->support_finding('php', 'PHP', '8.1', '2025-12-31', $now)->severity);
        $this->assertSame(base_check::SEVERITY_INFO, $check->support_finding('php', 'PHP', '8.2', '2026-12-31', $now)->severity);
        $this->assertNull($check->support_finding('php', 'PHP', '8.3', '2027-12-31', $now));
        $this->assertNull($check->support_finding('php', 'PHP', '9.0', null, $now));
        $this->assertSame('16', phpdbsupport::branch('postgres', '16.4'));
        $this->assertSame('10.11', phpdbsupport::branch('mariadb', '10.11.8'));
        $this->assertSame('8.0', phpdbsupport::branch('mysql', '8.0.36'));
        foreach (phpdbsupport::PHP_END as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
        }
    }

    /**
     * The next Moodle branch, and add-on plugins without declared support for it.
     */
    public function test_next_branch(): void {
        $this->assertSame(500, upgradeready::next_branch(405));
        $this->assertSame(503, upgradeready::next_branch(502));
        $this->assertNull(upgradeready::next_branch(503));
        $this->assertSame('5.0', upgradeready::branch_name(500));
    }

    /**
     * The template cache fix turns it on, and undo turns it off again.
     */
    public function test_caching_fix(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        site_scenario::get_all()['caching']->fail($this->getDataGenerator());
        foreach (array_keys(check_registry::get_checks([base_check::SCOPE_SITE])) as $id) {
            if ($id !== 'caching') {
                set_config('enabled_' . $id, 0, 'local_chronifycompliance');
            }
        }
        check_registry::reset();
        site_auditor::audit(true);
        $finding = array_values(array_filter(finding_store::get_course_findings((int) SITEID), fn($f) => $f->fixable))[0];
        $this->assertSame('caching', $finding->checkid);
        $result = fixer::apply((int) $finding->id, [], (int) get_admin()->id);
        $this->assertSame('1', (string) get_config('core', 'cachetemplates'));
        fixer::undo($result->fixlogid, (int) get_admin()->id);
        $this->assertSame('0', (string) get_config('core', 'cachetemplates'));
    }
}
