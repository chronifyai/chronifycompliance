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

#[\PHPUnit\Framework\Attributes\CoversClass(explainer::class)]
/**
 * Tests of the learner completion explainer.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\learner\explainer
 */
final class explainer_test extends \advanced_testcase {
    /**
     * Unmet criteria block; all met but not aggregated is "pending"; nothing is written.
     */
    public function test_blocking_and_pending(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = (new \local_chronifycompliance\tests\demo\scenario\healthy())->create(
            $generator,
            (int) $generator->create_category()->id
        );
        $student = $generator->create_and_enrol($course, 'student');

        $result = explainer::explain((int) $course->id, (int) $student->id);
        $this->assertTrue($result['enabled']);
        $this->assertTrue($result['enrolled']);
        $this->assertFalse($result['completed']);
        $this->assertSame(1, $result['blocking']);
        $this->assertCount(1, $result['criteria']);
        $criterion = $result['criteria'][0];
        $this->assertTrue($criterion['blocking']);
        $this->assertSame('assign', $DB->get_field('modules', 'name', ['id' => $DB->get_field(
            'course_modules',
            'module',
            ['id' => $criterion['activity']['cmid']]
        )]));
        $this->assertNotEmpty($criterion['activity']['rules']);

        // Criterion met but course completion not aggregated yet.
        $before = $DB->count_records('course_completions');
        $DB->insert_record('course_completion_crit_compl', ['userid' => $student->id, 'course' => $course->id,
            'criteriaid' => $criterion['id'], 'timecompleted' => time()]);
        $result = explainer::explain((int) $course->id, (int) $student->id);
        $this->assertTrue($result['pendingaggregation']);
        $this->assertSame(0, $result['blocking']);
        $this->assertSame($before, $DB->count_records('course_completions'), 'The explainer writes nothing');
    }
}
