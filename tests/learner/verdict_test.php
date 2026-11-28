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

namespace local_chronifycompliance\learner;

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(verdict::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\output\learner_report::class)]
/**
 * Tests of the learner diagnostic verdict and summary.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\learner\verdict
 * @covers \local_chronifycompliance\output\learner_report
 */
final class verdict_test extends \advanced_testcase {
    /**
     * Seed, check and enrol a learner.
     *
     * @param string $scenario
     * @return array [course, user, explanation]
     */
    private function learner_in(string $scenario): array {
        $courseid = seeder::seed([$scenario])[$scenario];
        course_auditor::audit($courseid, true);
        $course = get_course($courseid);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $user, explainer::explain($courseid, (int) $user->id)];
    }

    /**
     * A healthy course: the learner still has work to do, named in the verdict and the summary.
     */
    public function test_learner_cause(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $user, $explanation] = $this->learner_in('healthy');
        $verdict = verdict::decide((int) $course->id, $explanation);
        $this->assertSame(verdict::LEARNER, $verdict['kind']);
        $this->assertCount(1, $verdict['todo']);
        $this->assertNull($verdict['finding']);
        $this->assertSame(
            get_string('learner:status:blockingone', 'local_chronifycompliance'),
            verdict::status_text($explanation)
        );

        $summary = verdict::summary($course, $user, $explanation, $verdict);
        $this->assertStringContainsString(fullname($user), $summary);
        $this->assertStringContainsString(format_string($course->fullname), $summary);
        $this->assertStringContainsString('- ' . get_string('learner:blocks', 'local_chronifycompliance') . ': ', $summary);
        $this->assertStringContainsString($verdict['todo'][0], $summary);
        $this->assertStringNotContainsString('<', $summary, 'Plain text');
    }

    /**
     * A blocking condition with a course problem behind it is a setup problem, with the finding and its fix.
     */
    public function test_setup_cause(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['requireddeleted', 'passgradeunreachable'] as $scenario) {
            [$course, $user, $explanation] = $this->learner_in($scenario);
            $verdict = verdict::decide((int) $course->id, $explanation);
            $this->assertSame(verdict::SETUP, $verdict['kind'], $scenario);
        }
        // Pass grade above the maximum: the finding is named and fixable.
        $this->assertNotNull($verdict['finding']);
        $this->assertSame('passgradeunreachable', $verdict['finding']->checkid);
        $this->assertStringContainsString($verdict['finding']->get_message(), verdict::verdict_text($verdict));

        $PAGE->set_url('/local/chronifycompliance/learner.php');
        $PAGE->set_context(\context_course::instance($course->id));
        $report = new \local_chronifycompliance\output\learner_report($course, $user, $explanation, [], '');
        $data = $report->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($data['verdict']['issetup']);
        $this->assertSame((int) $verdict['finding']->id, $data['verdict']['fixid']);
        $html = $PAGE->get_renderer('local_chronifycompliance')->learner_report($course, $user, $explanation, [], '');
        $this->assertStringContainsString('data-action="copytoclipboard"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Completed, waiting for aggregation, not enrolled.
     */
    public function test_other_kinds(): void {
        $base = ['enabled' => true, 'enrolled' => true, 'completed' => false, 'pendingaggregation' => false,
            'blocking' => 0, 'criteria' => []];
        $this->resetAfterTest();
        $this->assertSame(verdict::COMPLETE, verdict::decide(1, ['completed' => true] + $base)['kind']);
        $this->assertSame(verdict::WAITING, verdict::decide(1, ['pendingaggregation' => true] + $base)['kind']);
        $this->assertSame(verdict::NOTENROLLED, verdict::decide(1, ['enrolled' => false] + $base)['kind']);
        $this->assertSame(verdict::SETUP, verdict::decide(1, ['enabled' => false] + $base)['kind']);
    }
}
