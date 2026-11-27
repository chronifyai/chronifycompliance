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

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\tests\demo\seeder;
use local_chronifycompliance\scorm\inspector;

#[\PHPUnit\Framework\Attributes\CoversClass(renderer::class)]
/**
 * Smoke tests: every page renders through the real templates for every demo course.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\renderer
 */
final class renderer_test extends \advanced_testcase {
    /**
     * Render the course report, SCORM panels and learner diagnostic of all demo courses.
     */
    public function test_pages_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/chronifycompliance/course.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('local_chronifycompliance');

        foreach (seeder::seed() as $id => $courseid) {
            course_auditor::audit($courseid, true);
            $course = get_course($courseid);
            $html = $renderer->course_report($course);
            $this->assertStringContainsString('data-region="course-report"', $html, $id);
            $this->assertStringNotContainsString('[[', $html, "Missing string in {$id}");
            $this->assertStringNotContainsString('}}', $html, "Broken template comment in {$id}");

            foreach (get_fast_modinfo($courseid)->get_instances_of('scorm') as $cm) {
                $panel = $renderer->scorm_panel($cm, inspector::inspect($cm));
                $this->assertStringContainsString('data-region="scorm-panel"', $panel, $id);
                $this->assertStringNotContainsString('[[', $panel, "Missing string in SCORM panel of {$id}");
            }

            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $learner = $renderer->learner_report(
                $course,
                $student,
                \local_chronifycompliance\learner\explainer::explain($courseid, (int) $student->id),
                [],
                ''
            );
            $this->assertStringNotContainsString('[[', $learner, "Missing string in learner diagnostic of {$id}");
        }
    }

    /**
     * The dashboard header and the report render for every view, with correct course counts.
     */
    public function test_dashboard_renders(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/chronifycompliance/index.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('local_chronifycompliance');

        $generator = $this->getDataGenerator();
        $categoryid = (int) $generator->create_category()->id;
        $healthy = (new \local_chronifycompliance\tests\demo\scenario\healthy())->create($generator, $categoryid);
        $broken = (new \local_chronifycompliance\tests\demo\scenario\nocriteria())->create($generator, $categoryid);
        course_auditor::audit((int) $healthy->id, true);
        course_auditor::audit((int) $broken->id, true);

        $summary = \local_chronifycompliance\local\dashboard_data::get_summary();
        $this->assertSame(1, $summary['critical']);
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(50, $summary['healthypercent']);
        $problems = \local_chronifycompliance\local\dashboard_data::get_top_problems();
        $this->assertSame('nocriteria', $problems[0]['checkid']);

        foreach (['', 'critical', 'healthy'] as $view) {
            $html = $renderer->dashboard_header($view, '');
            $this->assertStringContainsString('lcd-tile-critical', $html);
            $this->assertStringNotContainsString('[[', $html);
            $report = \core_reportbuilder\system_report_factory::create(
                \local_chronifycompliance\reportbuilder\local\systemreports\dashboard::class,
                \context_system::instance(),
                '',
                '',
                0,
                ['view' => $view, 'checkid' => '']
            );
            $this->assertNotEmpty($report->output());
        }
        $html = $renderer->dashboard_header('', 'nocriteria');
        $this->assertStringContainsString(get_string('check:nocriteria:name', 'local_chronifycompliance'), $html);
        $report = \core_reportbuilder\system_report_factory::create(
            \local_chronifycompliance\reportbuilder\local\systemreports\dashboard::class,
            \context_system::instance(),
            '',
            '',
            0,
            ['view' => '', 'checkid' => 'nocriteria']
        );
        $this->assertNotEmpty($report->output());
    }

    /**
     * A package waiting for analysis is announced on the course report until it is analyzed.
     */
    public function test_waiting_package_notice(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('inlineanalysismb', 0, 'local_chronifycompliance');
        $PAGE->set_url('/local/chronifycompliance/course.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('local_chronifycompliance');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $path = \core_component::get_component_directory('local_chronifycompliance')
            . '/tests/fixtures/packages/storyline_fake.zip';
        $scorm = $generator->create_module('scorm', ['course' => $course->id, 'packagefilepath' => $path]);
        course_auditor::audit((int) $course->id, true);

        $html = $renderer->course_report(get_course($course->id));
        $this->assertStringContainsString('data-region="scorm-waiting"', $html);
        $this->assertStringContainsString('name="cmid" value="' . $scorm->cmid . '"', $html);

        inspector::inspect(get_fast_modinfo($course->id)->get_cm($scorm->cmid), inspector::MODE_NOW);
        $html = $renderer->course_report(get_course($course->id));
        $this->assertStringNotContainsString('data-region="scorm-waiting"', $html);
    }
}
