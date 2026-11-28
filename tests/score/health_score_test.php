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

namespace local_chronifycompliance\score;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\task\score_snapshot;
use local_chronifycompliance\tests\demo\seeder;

#[\PHPUnit\Framework\Attributes\CoversClass(health_score::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(score_snapshot::class)]
/**
 * Tests for the health score: the formula, pinned scores of every demo scenario, snapshots and history.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\score\health_score
 * @covers \local_chronifycompliance\task\score_snapshot
 */
final class health_score_test extends \advanced_testcase {
    /**
     * The formula tests use the Completion, Site and SCORM packs only: security checks and Moodle's own checks are off.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled_coreperformance', 0, 'local_chronifycompliance');
        $security = \local_chronifycompliance\check\check_registry::get_checks(null, false, [base_check::PACK_SECURITY]);
        foreach (array_keys($security) as $id) {
            set_config('enabled_' . $id, 0, 'local_chronifycompliance');
        }
        \local_chronifycompliance\check\check_registry::reset();
    }

    /**
     * Pinned course scores of the demo scenarios: overall, Completion pack, SCORM and content pack.
     *
     * A change here means the checks or the formula changed: update it on purpose.
     */
    private const PINNED = [
        'addedaftercriteria' => [98, 97, 100],
        'circular' => [93, 90, 100],
        'healthy' => [100, 100, 100],
        'manualrole' => [93, 90, 100],
        'nocriteria' => [93, 90, 100],
        'nopassgrade' => [93, 90, 100],
        'overlapgrade' => [98, 97, 100],
        'passgradeunreachable' => [93, 90, 100],
        'prereqbroken' => [93, 90, 100],
        'requireddeleted' => [93, 90, 100],
        'scormgradenoscore' => [97, 100, 90],
        'scormheavy' => [100, 100, 100],
        'scormmultibuild' => [97, 100, 90],
        'scormmultisco' => [99, 100, 97],
        'scormnostatus' => [97, 100, 90],
        'scormpassgrade' => [99, 100, 97],
        'scormquiz' => [100, 100, 100],
        'scormreplaced' => [99, 100, 97],
        'scormrequireview' => [99, 100, 97],
        'scormstage' => [100, 100, 100],
        'scormstatusmismatch' => [99, 100, 97],
        'scormsuspenddata' => [99, 100, 97],
        'selfcompletionblock' => [93, 90, 100],
        'trackednotrequired' => [98, 97, 100],
        'trackingoff' => [100, 100, 100],
        'unreachable' => [93, 90, 100],
        'weakcondition' => [100, 100, 100],
    ];

    /**
     * Add a stored finding.
     *
     * @param int $courseid
     * @param string $checkid
     * @param int $severity
     * @param string $status
     */
    private function add_finding(int $courseid, string $checkid, int $severity, string $status = finding::STATUS_OPEN): void {
        global $DB;
        static $n = 0;
        $n++;
        $DB->insert_record('local_chronifycompliance_finding', [
            'courseid' => $courseid, 'checkid' => $checkid, 'component' => 'local_chronifycompliance',
            'severity' => $severity, 'baseseverity' => $severity, 'fingerprint' => sha1('f' . $n), 'status' => $status,
            'messagekey' => 'x', 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * The formula: penalties per severity, ignored findings at half, weighted overall over packs with checks.
     */
    public function test_formula(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $score = health_score::site();
        $this->assertSame(100, $score->overall);
        $this->assertSame('healthy', $score->status);
        $this->assertSame(
            [base_check::PACK_COMPLETION, base_check::PACK_SITE, base_check::PACK_SCORM],
            array_keys($score->packs),
            'Security has no enabled checks, so it is left out.'
        );

        // Completion: 1 critical + 2 warnings + 1 info + 1 ignored critical = 10 + 6 + 0.5 + 5 = 21.5.
        $this->add_finding($course->id, 'nopassgrade', base_check::SEVERITY_CRITICAL);
        $this->add_finding($course->id, 'weakcondition', base_check::SEVERITY_WARNING);
        $this->add_finding($course->id, 'weakcondition', base_check::SEVERITY_WARNING);
        $this->add_finding($course->id, 'manualrole', base_check::SEVERITY_INFO);
        $this->add_finding($course->id, 'circular', base_check::SEVERITY_CRITICAL, finding::STATUS_IGNORED);
        // Resolved and fixed findings do not count.
        $this->add_finding($course->id, 'circular', base_check::SEVERITY_CRITICAL, finding::STATUS_RESOLVED);
        $this->add_finding($course->id, 'circular', base_check::SEVERITY_CRITICAL, finding::STATUS_FIXED);
        // Site: 2 criticals = 20.
        $this->add_finding(SITEID, 'cronstale', base_check::SEVERITY_CRITICAL);
        $this->add_finding(SITEID, 'completiontasks', base_check::SEVERITY_CRITICAL);

        $score = health_score::site();
        $completion = $score->packs[base_check::PACK_COMPLETION];
        $this->assertSame(79, $completion->score, '100 - 21.5, rounded');
        $this->assertSame([1, 2, 1], [$completion->critical, $completion->warning, $completion->info], 'Open findings only');
        $this->assertSame(80, $score->packs[base_check::PACK_SITE]->score);
        $this->assertSame(100, $score->packs[base_check::PACK_SCORM]->score);
        // Overall: (79 x 30 + 80 x 20 + 100 x 15) / 65 = 84.15.
        $this->assertSame(84, $score->overall);

        // A disabled check stops counting.
        set_config('enabled_nopassgrade', 0, 'local_chronifycompliance');
        $this->assertSame(89, health_score::site()->packs[base_check::PACK_COMPLETION]->score);
        set_config('enabled_nopassgrade', 1, 'local_chronifycompliance');

        // The course score ignores site findings and site packs.
        $coursescore = health_score::course($course->id);
        $this->assertSame([base_check::PACK_COMPLETION, base_check::PACK_SCORM], array_keys($coursescore->packs));
        $this->assertSame(79, $coursescore->packs[base_check::PACK_COMPLETION]->score);
    }

    /**
     * Site scores count course findings per 100 audited courses; the floor is 0.
     */
    public function test_large_sites_and_floor(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < 12; $i++) {
            $this->add_finding($course->id, 'nopassgrade', base_check::SEVERITY_CRITICAL);
        }
        $this->assertSame(0, health_score::site()->packs[base_check::PACK_COMPLETION]->score, 'Never below 0');

        // 400 audited courses: 12 criticals weigh like 3 on a site of 100 courses.
        $audits = [];
        for ($i = 0; $i < 400; $i++) {
            $audits[] = ['courseid' => 100000 + $i, 'timeaudited' => time()];
        }
        $DB->insert_records('local_chronifycompliance_audit', $audits);
        $this->assertSame(70, health_score::site()->packs[base_check::PACK_COMPLETION]->score);
        $coursescore = health_score::course($course->id)->packs[base_check::PACK_COMPLETION]->score;
        $this->assertSame(0, $coursescore, 'Courses are not scaled');
    }

    /**
     * Status labels.
     */
    public function test_status(): void {
        $this->assertNull(health_score::status(null));
        $this->assertSame('healthy', health_score::status(80));
        $this->assertSame('attention', health_score::status(79));
        $this->assertSame('attention', health_score::status(50));
        $this->assertSame('atrisk', health_score::status(49));
    }

    /**
     * Every demo scenario gets its pinned course score after a real audit.
     */
    public function test_demo_scenarios_pinned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $courseids = seeder::seed();
        site_auditor::audit(true);
        $actual = [];
        foreach ($courseids as $id => $courseid) {
            course_auditor::audit($courseid, true);
            $score = health_score::course($courseid);
            $actual[$id] = [
                $score->overall,
                $score->packs[base_check::PACK_COMPLETION]->score,
                $score->packs[base_check::PACK_SCORM]->score,
            ];
        }
        ksort($actual);
        $this->assertSame(self::PINNED, $actual);
    }

    /**
     * The weekly task stores a snapshot per pack and overall; history returns the latest, oldest first.
     */
    public function test_snapshots(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        health_score::snapshot(time() - 3 * WEEKSECS);
        $this->add_finding($course->id, 'nopassgrade', base_check::SEVERITY_CRITICAL);
        health_score::snapshot(time() - 2 * WEEKSECS);
        ob_start();
        (new score_snapshot())->execute();
        ob_end_clean();

        $this->assertSame(4 * 3, $DB->count_records(health_score::TABLE), 'Overall and three packs, three times');
        $history = health_score::history(health_score::OVERALL, 2);
        $this->assertCount(2, $history);
        $this->assertLessThan($history[1]->timecreated, $history[0]->timecreated);
        $completion = health_score::history(base_check::PACK_COMPLETION);
        $this->assertSame(['100', '90', '90'], array_map(fn($row) => (string) $row->score, $completion));

        $DB->set_field(health_score::TABLE, 'timecreated', time() - 3 * YEARSECS, []);
        $this->assertSame(12, health_score::delete_old());
    }

    /**
     * Points each check costs, capped at what its pack can lose, and last week's score.
     */
    public function test_breakdown_and_last_week(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame([], health_score::site_breakdown());

        // Completion: 10 + 3 = 13 penalty, weight 30 of 65. Site: 10, weight 20 of 65.
        $this->add_finding($course->id, 'nopassgrade', base_check::SEVERITY_CRITICAL);
        $this->add_finding($course->id, 'weakcondition', base_check::SEVERITY_WARNING);
        $this->add_finding(SITEID, 'cronstale', base_check::SEVERITY_CRITICAL);
        $points = health_score::site_breakdown();
        $this->assertSame(['nopassgrade', 'cronstale', 'weakcondition'], array_keys($points));
        $this->assertEqualsWithDelta(10 * 30 / 65, $points['nopassgrade'], 0.001);
        $this->assertEqualsWithDelta(10 * 20 / 65, $points['cronstale'], 0.001);
        $this->assertEqualsWithDelta(3 * 30 / 65, $points['weakcondition'], 0.001);

        // A pack at 0 shares its 100 points between its checks.
        for ($i = 0; $i < 19; $i++) {
            $this->add_finding($course->id, 'nopassgrade', base_check::SEVERITY_CRITICAL);
        }
        $points = health_score::site_breakdown();
        $this->assertEqualsWithDelta(100 * 30 / 65, $points['nopassgrade'] + $points['weakcondition'], 0.001);

        $now = time();
        $this->assertNull(health_score::last_week($now));
        $DB->insert_records(health_score::TABLE, [
            ['timecreated' => $now - 8 * DAYSECS, 'pack' => health_score::OVERALL, 'score' => 70, 'critical' => 0,
                'warning' => 0, 'info' => 0],
            ['timecreated' => $now - 2 * DAYSECS, 'pack' => health_score::OVERALL, 'score' => 75, 'critical' => 0,
                'warning' => 0, 'info' => 0],
        ]);
        $this->assertSame(70, health_score::last_week($now), 'The snapshot of two days ago is too recent');
    }
}
