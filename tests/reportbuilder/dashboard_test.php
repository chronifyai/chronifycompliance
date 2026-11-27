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

namespace local_completiondoctor\reportbuilder;

use core_reportbuilder\system_report_factory;
use local_completiondoctor\local\dashboard_data;
use local_completiondoctor\reportbuilder\local\systemreports\dashboard;

/**
 * Tests for the site dashboard system report.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dashboard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dashboard_data::class)]
final class dashboard_test extends \advanced_testcase {
    /**
     * Downloads have no row actions: core builds them after the session is closed.
     */
    public function test_no_actions_in_download(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $create = fn() => system_report_factory::create(
            dashboard::class,
            \context_system::instance(),
            '',
            '',
            0,
            ['view' => '', 'checkid' => '']
        );
        $this->assertTrue($create()->has_actions());

        // A download is a new request; core keeps report instances for the rest of this one.
        \core_reportbuilder\manager::reset_caches();
        $_GET['download'] = 'csv';
        try {
            $this->assertFalse($create()->has_actions());
        } finally {
            unset($_GET['download']);
        }
    }

    /**
     * A course is counted and listed under every severity it has open findings of.
     */
    public function test_severity_groups_overlap(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/completiondoctor/index.php');
        $generator = $this->getDataGenerator();
        $audits = [
            'Mixed course' => [1, 1, 1],
            'Critical course' => [1, 0, 0],
            'Warning course' => [0, 1, 0],
            'Healthy course' => [0, 0, 0],
        ];
        foreach ($audits as $name => [$critical, $warning, $info]) {
            $course = $generator->create_course(['fullname' => $name, 'enablecompletion' => 1]);
            $DB->insert_record('local_completiondoctor_audit', ['courseid' => $course->id, 'timeaudited' => time(),
                'critical' => $critical, 'warning' => $warning, 'info' => $info]);
        }

        $summary = dashboard_data::get_summary();
        $this->assertSame(4, $summary['audited']);
        $this->assertSame(2, $summary['critical']);
        $this->assertSame(2, $summary['warning']);
        $this->assertSame(1, $summary['info']);
        $this->assertSame(1, $summary['healthy']);

        $expected = [
            'critical' => ['Mixed course', 'Critical course'],
            'warning' => ['Mixed course', 'Warning course'],
            'info' => ['Mixed course'],
            'healthy' => ['Healthy course'],
        ];
        foreach ($expected as $view => $names) {
            \core_reportbuilder\manager::reset_caches();
            $report = system_report_factory::create(
                dashboard::class,
                \context_system::instance(),
                '',
                '',
                0,
                ['view' => $view, 'checkid' => '']
            );
            $html = $report->output();
            foreach (array_keys($audits) as $name) {
                if (in_array($name, $names, true)) {
                    $this->assertStringContainsString($name, $html, "{$name} in view {$view}");
                } else {
                    $this->assertStringNotContainsString($name, $html, "{$name} in view {$view}");
                }
            }
        }
    }
}
