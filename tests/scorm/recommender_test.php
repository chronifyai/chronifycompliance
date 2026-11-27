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

namespace local_completiondoctor\scorm;

/**
 * Tests of the runtime evidence and the recommender.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(recommender::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(runtime_evidence::class)]
final class recommender_test extends \advanced_testcase {
    /**
     * A scorm record with completion settings.
     *
     * @param array $fields
     * @return \stdClass
     */
    private function scorm(array $fields = []): \stdClass {
        return (object) ($fields + ['completionstatusrequired' => 4, 'completionscorerequired' => null,
            'completionstatusallscos' => 0, 'masteryoverride' => 1, 'forcenewattempt' => 0]);
    }

    /**
     * Evidence with status values.
     *
     * @param array $values
     * @param int $withscore
     * @return array
     */
    private function evidence(array $values, int $withscore = 0): array {
        return ['attempts' => 10, 'values' => $values, 'statuses' => [], 'withstatus' => $values ? 10 : 0,
            'nostatus' => $values ? 0 : 10, 'withscore' => $withscore, 'sufficient' => true];
    }

    /**
     * Evidence beats the profile; the recommendation follows what was reported.
     */
    public function test_recommendations(): void {
        $package = ['manifest' => ['version' => 'SCORM_1.2', 'scocount' => 1, 'masteryscore' => null],
            'tool' => ['reportingmode' => 'completed-incomplete', 'passmark' => null]];

        $result = recommender::recommend($package, $this->evidence(['passed' => 5, 'incomplete' => 9]), $this->scorm());
        $this->assertSame('high', $result['confidence']);
        $this->assertSame(recommender::PASSED, $result['settings']['completionstatusrequired']);
        $this->assertSame([4, 2], $result['diff']['completionstatusrequired']);
        $this->assertSame('completednotsent', recommender::get_mismatch($result['behavior'], $this->scorm()));

        $result = recommender::recommend($package, $this->evidence(['passed' => 1, 'completed' => 3]), $this->scorm());
        $this->assertSame(recommender::EITHER, $result['settings']['completionstatusrequired']);

        $result = recommender::recommend($package, ['sufficient' => false], $this->scorm(['completionstatusrequired' => 2]));
        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(recommender::COMPLETED, $result['settings']['completionstatusrequired']);
        $this->assertSame('passednotsent', recommender::get_mismatch(
            $result['behavior'],
            $this->scorm(['completionstatusrequired' => 2])
        ));

        // Only "incomplete" seen: nobody finished, so the tool settings decide.
        $result = recommender::recommend(
            $package,
            $this->evidence(['incomplete' => 9]),
            $this->scorm(['completionstatusrequired' => 2])
        );
        $this->assertSame('profile', $result['behavior']['basis']);
        $this->assertSame(recommender::COMPLETED, $result['settings']['completionstatusrequired']);

        // SCORM 2004 "passed" also sends completed.
        $package2004 = ['manifest' => ['version' => 'SCORM_1.3', 'scocount' => 2],
            'tool' => ['reportingmode' => 'passed-incomplete']];
        $result = recommender::recommend($package2004, ['sufficient' => false], $this->scorm());
        $this->assertSame(recommender::EITHER, $result['settings']['completionstatusrequired']);
        $this->assertSame(1, $result['settings']['completionstatusallscos']);
        $this->assertNull(recommender::get_mismatch($result['behavior'], $this->scorm()));
    }

    /**
     * No status: score only, or a warning; mastery score and forced attempts warn.
     */
    public function test_no_status_and_warnings(): void {
        $package = ['manifest' => ['version' => 'SCORM_1.2', 'scocount' => 1, 'masteryscore' => 70.0], 'tool' => []];
        $result = recommender::recommend($package, $this->evidence([], 10), $this->scorm(['forcenewattempt' => 2]));
        $this->assertNull($result['settings']['completionstatusrequired']);
        $this->assertSame(70, $result['settings']['completionscorerequired']);
        $warnings = array_column($result['warnings'], 'str');
        $this->assertContains('rec:warning:masteryscore', $warnings);
        $this->assertContains('rec:warning:forcenewattempt', $warnings);

        $result = recommender::recommend($package, $this->evidence([]), $this->scorm());
        $this->assertContains('rec:warning:nostatus', array_column($result['warnings'], 'str'));
        $this->assertContains('rec:warning:forcecompleted', array_column($result['warnings'], 'str'));

        // Manifest only: low confidence, passed expected because of the mastery score.
        $result = recommender::recommend($package, ['sufficient' => false], $this->scorm());
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(recommender::PASSED, $result['settings']['completionstatusrequired']);
        $this->assertNull(recommender::get_mismatch($result['behavior'], $this->scorm()), 'Manifest basis is not trusted');
    }

    /**
     * Evidence is aggregated from the latest attempts only.
     */
    public function test_runtime_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $scorm = $generator->create_module('scorm', ['course' => $course->id]);
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        $element = fn(string $name) => $DB->get_field('scorm_element', 'id', ['element' => $name])
            ?: $DB->insert_record('scorm_element', ['element' => $name]);
        $values = [['completed', 90], ['completed', null], ['incomplete', null], [null, null], ['passed', 50], ['passed', 80]];
        foreach ($values as $i => [$status, $score]) {
            $user = $generator->create_user();
            $attemptid = $DB->insert_record('scorm_attempt', ['userid' => $user->id, 'scormid' => $scorm->id, 'attempt' => 1]);
            if ($status) {
                $DB->insert_record('scorm_scoes_value', ['scoid' => $scoid, 'attemptid' => $attemptid,
                    'elementid' => $element('cmi.core.lesson_status'), 'value' => $status, 'timemodified' => 0]);
            }
            if ($score !== null) {
                $DB->insert_record('scorm_scoes_value', ['scoid' => $scoid, 'attemptid' => $attemptid,
                    'elementid' => $element('cmi.core.score.raw'), 'value' => (string) $score, 'timemodified' => 0]);
            }
        }
        $evidence = runtime_evidence::collect((int) $scorm->id);
        $this->assertSame(6, $evidence['attempts']);
        $this->assertSame(['completed' => 2, 'incomplete' => 1, 'passed' => 2], array_intersect_key(
            $evidence['values'],
            ['completed' => 1, 'incomplete' => 1, 'passed' => 1]
        ));
        $this->assertSame(5, $evidence['withstatus']);
        $this->assertSame(1, $evidence['nostatus']);
        $this->assertSame(3, $evidence['withscore']);
        $this->assertTrue($evidence['sufficient']);

        // Only the latest 2 attempts (both passed).
        $evidence = runtime_evidence::collect((int) $scorm->id, 2);
        $this->assertSame(['passed' => 2], $evidence['values']);
        $this->assertFalse($evidence['sufficient']);
    }
}
