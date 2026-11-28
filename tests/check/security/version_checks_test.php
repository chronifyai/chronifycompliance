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
use local_chronifycompliance\security\vulnerability_list;
use local_chronifycompliance\tests\demo\site_scenario;

#[\PHPUnit\Framework\Attributes\CoversClass(corevulnerabilities::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(availableupdates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(vulnerability_list::class)]
/**
 * Tests of the vulnerability list, its parser, and the version and update checks.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\security\corevulnerabilities
 * @covers \local_chronifycompliance\check\security\availableupdates
 * @covers \local_chronifycompliance\security\vulnerability_list
 */
final class version_checks_test extends \advanced_testcase {
    /**
     * Go back to the shipped list.
     */
    protected function tearDown(): void {
        vulnerability_list::set(null);
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
     * The shipped list loads, is valid and parses like the saved announcements page.
     */
    public function test_shipped_list(): void {
        global $CFG;
        $list = vulnerability_list::load();
        $this->assertSame(vulnerability_list::FORMAT, $list['format']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $list['generated']);
        $this->assertNotEmpty($list['advisories']);
        foreach ($list['advisories'] as $advisory) {
            $this->assertMatchesRegularExpression('/^MSA-\d{2}-\d{4}$/', $advisory['id']);
            $this->assertContains($advisory['severity'], ['serious', 'minor']);
            $this->assertNotEmpty($advisory['ranges'], $advisory['id']);
            $this->assertNotEmpty($advisory['fixed'], $advisory['id']);
        }

        $html = file_get_contents($CFG->dirroot . '/local/chronifycompliance/tests/fixtures/security_announcements.html');
        $parsed = vulnerability_list::parse_text(vulnerability_list::html_to_text($html));
        $this->assertCount(10, $parsed);
        $byid = array_column($parsed, null, 'id');
        $h5p = $byid['MSA-26-0044'];
        $this->assertSame('serious', $h5p['severity']);
        $this->assertSame([['5.2', '5.2.3'], ['5.1', '5.1.7'], ['5.0', '5.0.10'], ['4.5', '4.5.14']], $h5p['ranges']);
        $this->assertTrue($h5p['unsupported']);
        $this->assertSame(['5.2.4', '5.1.8', '5.0.11', '4.5.15'], $h5p['fixed']);
        $this->assertSame('MDL-89547', $h5p['tracker']);
        $this->assertSame(['CVE-2026-47759', 'CVE-2026-47761', 'CVE-2026-47762'], $byid['MSA-26-0045']['cves']);
        $this->assertFalse($byid['MSA-26-0038']['unsupported']);
        $title = 'Incorrect capability check in AI editor placement "generate image" service';
        $this->assertSame($title, $byid['MSA-26-0036']['title']);

        // Merging keeps known advisories and adds new ones, newest first.
        $merged = vulnerability_list::merge([['id' => 'MSA-25-0001'] + $h5p], $parsed);
        $this->assertCount(11, $merged);
        $this->assertSame('MSA-26-0045', $merged[0]['id']);
        $this->assertSame('MSA-25-0001', end($merged)['id']);
    }

    /**
     * Which releases are affected.
     */
    public function test_affecting(): void {
        $this->assertSame([4, 5, 16], vulnerability_list::parse_version('4.5.16+ (Build: 20261124)'));
        $this->assertSame([5, 0, 0], vulnerability_list::parse_version('5.0'));
        $ids = fn(string $release) => array_column(vulnerability_list::affecting($release), 'id');
        $this->assertContains('MSA-26-0044', $ids('4.5.14'));
        $this->assertNotContains('MSA-26-0044', $ids('4.5.15'));
        $this->assertContains('MSA-26-0044', $ids('4.4.9'), 'Earlier unsupported versions');
        $this->assertNotContains('MSA-26-0038', $ids('4.4.9'), 'Not marked as affecting unsupported versions');
        $this->assertSame([], $ids('5.2.4'));
        $fixedin = array_column(vulnerability_list::affecting('5.1.3'), 'fixedin', 'id');
        $this->assertSame('5.1.8', $fixedin['MSA-26-0044']);
    }

    /**
     * Data provider: the checks.
     *
     * @return array
     */
    public static function checks_provider(): array {
        return ['corevulnerabilities' => ['corevulnerabilities'], 'availableupdates' => ['availableupdates']];
    }

    /**
     * Each check fails and passes with its site scenario.
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
     * Messages and severities of the version check; an old list is reported.
     */
    public function test_version_cases(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->release = '4.5.12 (Build: 20260101)';
        $findings = $this->run_check('corevulnerabilities');
        $missing = array_values(array_filter($findings, fn($f) => $f->identity['case'] === 'missing'))[0];
        $this->assertSame(base_check::SEVERITY_CRITICAL, $missing->severity, 'Serious issues are Critical');
        $this->assertStringContainsString('Upgrade to 4.5.15 or later', $missing->get_message());

        $CFG->release = '4.3.2';
        $findings = $this->run_check('corevulnerabilities');
        $this->assertStringContainsString('no longer gets security fixes', $findings[0]->get_message());

        $list = vulnerability_list::load();
        vulnerability_list::set(['generated' => date('Y-m-d', time() - 400 * DAYSECS)] + $list);
        $CFG->release = '9.9.9';
        $findings = $this->run_check('corevulnerabilities');
        $this->assertSame([['case' => 'list']], array_map(fn($f) => $f->identity, $findings));
        $this->assertSame(base_check::SEVERITY_INFO, $findings[0]->severity);
    }

    /**
     * Moodle's stored update response: a newer build of this version and plugin updates are reported.
     */
    public function test_updates_found(): void {
        global $CFG;
        $this->resetAfterTest();
        site_scenario::get_all()['availableupdates']->pass($this->getDataGenerator());
        $branch = moodle_major_version();
        set_config('recentresponse', json_encode(['status' => 'OK', 'provider' => 'https://download.moodle.org',
            'apiver' => '1.3', 'timegenerated' => time(), 'forbranch' => moodle_major_version(true),
            'updates' => ['core' => [['version' => $CFG->version + 100, 'release' => $branch . '.99 (Build: 20991231)',
                'maturity' => MATURITY_STABLE, 'url' => 'https://download.moodle.org']]]]), 'core_plugin');
        \core\update\checker::reset_caches(true);
        $findings = $this->run_check('availableupdates');
        $this->assertSame(['core'], array_map(fn($f) => $f->identity['case'], $findings));
        $this->assertStringContainsString($branch . '.99', $findings[0]->get_message());
    }
}
