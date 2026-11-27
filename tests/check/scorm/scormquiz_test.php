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

namespace local_chronifycompliance\check\scorm;

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fixer;
use local_chronifycompliance\local\finding_store;

#[\PHPUnit\Framework\Attributes\CoversClass(scormquiz::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\scorm\runtime_evidence::class)]
/**
 * Tests of the SCORM quiz and completion check.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\scorm\scormquiz
 * @covers \local_chronifycompliance\scorm\runtime_evidence
 */
final class scormquiz_test extends \advanced_testcase {
    /**
     * Prepare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        check_registry::reset();
    }

    /**
     * A course with one required SCORM activity.
     *
     * @param string $fixture Package in tests/fixtures/packages.
     * @param array $settings SCORM settings.
     * @return array [course, scorm module]
     */
    private function course(string $fixture, array $settings): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $path = \core_component::get_component_directory('local_chronifycompliance') . "/tests/fixtures/packages/{$fixture}.zip";
        $scorm = $generator->create_module('scorm', $settings + ['course' => $course->id, 'packagefilepath' => $path,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionstatusrequired' => 4]);
        return [$course, $scorm];
    }

    /**
     * Findings of the check.
     *
     * @param int $courseid
     * @return finding[]
     */
    private function audit(int $courseid): array {
        $result = course_auditor::audit($courseid, true, ['scormquiz']);
        $this->assertSame([], $result->errors);
        return array_values(array_filter(finding_store::get_course_findings($courseid), fn($f) => $f->checkid === 'scormquiz'));
    }

    /**
     * The quiz is ignored when the package completes without it: info, fixed by requiring its pass mark.
     */
    public function test_notrequired_and_fix(): void {
        global $DB;
        [$course, $scorm] = $this->course('storyline_fake', []);
        $findings = $this->audit((int) $course->id);
        $this->assertCount(1, $findings);
        $this->assertSame('check:scormquiz:messagenotrequired', $findings[0]->messagekey);
        $this->assertSame(\local_chronifycompliance\check\base_check::SEVERITY_INFO, $findings[0]->severity);

        $result = fixer::apply($findings[0]->id, [], 2);
        $this->assertEquals(80, $DB->get_field('scorm', 'completionscorerequired', ['id' => $scorm->id]));
        $this->assertSame([], $this->audit((int) $course->id));
        fixer::undo($result->fixlogid, 2);
        $this->assertNull($DB->get_field('scorm', 'completionscorerequired', ['id' => $scorm->id]));
    }

    /**
     * A minimum score that differs from the pass mark is a warning, either way.
     */
    public function test_differs(): void {
        [$course] = $this->course('storyline_fake', ['completionscorerequired' => 90]);
        $findings = $this->audit((int) $course->id);
        $this->assertSame('check:scormquiz:messagehigher', $findings[0]->messagekey);
        $this->assertSame(\local_chronifycompliance\check\base_check::SEVERITY_WARNING, $findings[0]->severity);

        [$course] = $this->course('storyline_fake', ['completionscorerequired' => 60]);
        $this->assertSame('check:scormquiz:messagelower', $this->audit((int) $course->id)[0]->messagekey);

        [$course] = $this->course('storyline_fake', ['completionscorerequired' => 80]);
        $this->assertSame([], $this->audit((int) $course->id), 'Minimum score equal to the pass mark');
    }

    /**
     * No finding when "passed" is required from a package that only reports "passed" after the quiz,
     * when the package has no quiz, or when the status can never be met (another check reports it).
     */
    public function test_quiet_cases(): void {
        global $DB;
        [$course, $scorm] = $this->course('storyline_fake', []);
        // The package profile says "completed"; make it report "passed" like a Storyline quiz with pass/incomplete.
        $DB->set_field('scorm', 'completionstatusrequired', 2, ['id' => $scorm->id]);
        $DB->execute("UPDATE {local_chronifycompliance_pkg} SET analysis = REPLACE(analysis, 'completed-incomplete',
            'passed-incomplete')");
        \local_chronifycompliance\scorm\runtime_evidence::purge();
        $this->assertSame([], $this->audit((int) $course->id), 'Package needs the quiz to report passed');

        [$course] = $this->course('scorm12_single', []);
        $this->assertSame([], $this->audit((int) $course->id), 'No quiz in the package');
    }

    /**
     * Scores out of 10 points can never reach a minimum score of 80: critical, with a proportional fix.
     */
    public function test_unreachable(): void {
        global $DB;
        [$course, $scorm] = $this->course('storyline_fake', ['completionscorerequired' => 80]);
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        $element = fn(string $name) => $DB->get_field('scorm_element', 'id', ['element' => $name])
            ?: $DB->insert_record('scorm_element', ['element' => $name]);
        foreach ([10, 7] as $raw) {
            $user = $this->getDataGenerator()->create_user();
            $attemptid = $DB->insert_record('scorm_attempt', ['userid' => $user->id, 'scormid' => $scorm->id, 'attempt' => 1]);
            foreach (['cmi.core.score.raw' => $raw, 'cmi.core.score.max' => 10] as $name => $value) {
                $DB->insert_record('scorm_scoes_value', ['scoid' => $scoid, 'attemptid' => $attemptid,
                    'elementid' => $element($name), 'value' => (string) $value, 'timemodified' => time()]);
            }
        }
        $evidence = \local_chronifycompliance\scorm\runtime_evidence::collect((int) $scorm->id);
        $this->assertSame(10.0, $evidence['maxraw']);
        $this->assertSame(['10' => 2], $evidence['scoremax']);

        \local_chronifycompliance\scorm\runtime_evidence::purge();
        $findings = $this->audit((int) $course->id);
        $this->assertSame('check:scormquiz:messageunreachable', $findings[0]->messagekey);
        $this->assertSame(\local_chronifycompliance\check\base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertSame(['completionscorerequired' => 8], $findings[0]->details['recommended']);
    }
}
