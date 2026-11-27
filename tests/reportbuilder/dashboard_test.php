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

namespace local_chronifycompliance\reportbuilder;

use core_reportbuilder\system_report_factory;
use local_chronifycompliance\local\dashboard_data;
use local_chronifycompliance\reportbuilder\local\systemreports\dashboard;

#[\PHPUnit\Framework\Attributes\CoversClass(dashboard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dashboard_data::class)]
/**
 * Tests for the site dashboard system report.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\reportbuilder\local\systemreports\dashboard
 * @covers \local_chronifycompliance\local\dashboard_data
 */
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
        $PAGE->set_url('/local/chronifycompliance/index.php');
        $generator = $this->getDataGenerator();
        $audits = [
            'Mixed course' => [1, 1, 1],
            'Critical course' => [1, 0, 0],
            'Warning course' => [0, 1, 0],
            'Healthy course' => [0, 0, 0],
        ];
        foreach ($audits as $name => [$critical, $warning, $info]) {
            $course = $generator->create_course(['fullname' => $name, 'enablecompletion' => 1]);
            $DB->insert_record('local_chronifycompliance_audit', ['courseid' => $course->id, 'timeaudited' => time(),
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

    /**
     * The SCORM view lists courses with open SCORM problems, and the critical column sorts worst first.
     */
    public function test_scorm_view_and_sort(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/chronifycompliance/completion.php');
        $generator = $this->getDataGenerator();
        $scorm = $generator->create_course(['fullname' => 'Scorm course']);
        $other = $generator->create_course(['fullname' => 'Other course']);
        $worse = $generator->create_course(['fullname' => 'Worse course']);
        foreach ([[$scorm, 1, 0], [$other, 1, 1], [$worse, 1, 5]] as [$course, $critical, $warning]) {
            $DB->insert_record('local_chronifycompliance_audit', ['courseid' => $course->id, 'timeaudited' => time(),
                'critical' => $critical, 'warning' => $warning, 'info' => 0]);
        }
        $DB->insert_record('local_chronifycompliance_finding', ['courseid' => $scorm->id, 'checkid' => 'scormnostatus',
            'component' => 'local_chronifycompliance', 'severity' => 3, 'baseseverity' => 3, 'fingerprint' => sha1('s'),
            'status' => 'open', 'messagekey' => 'x', 'timecreated' => time(), 'timemodified' => time()]);

        $html = system_report_factory::create(
            dashboard::class,
            \context_system::instance(),
            '',
            '',
            0,
            ['view' => 'scorm', 'checkid' => '']
        )->output();
        $this->assertStringContainsString('Scorm course', $html);
        $this->assertStringNotContainsString('Other course', $html);

        \core_reportbuilder\manager::reset_caches();
        $html = system_report_factory::create(
            dashboard::class,
            \context_system::instance(),
            '',
            '',
            0,
            ['view' => '', 'checkid' => '']
        )->output();
        $this->assertLessThan(strpos($html, 'Other course'), strpos($html, 'Worse course'));
        $this->assertLessThan(strpos($html, 'Scorm course'), strpos($html, 'Other course'));
    }
}
