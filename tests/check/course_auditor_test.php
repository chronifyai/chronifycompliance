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

namespace local_completiondoctor\check;

use local_completiondoctor\local\finding_store;
use local_completiondoctor\tests\check_testcase_trait;
use local_completiondoctor\tests\failing_check;
use local_completiondoctor\tests\fake_check;
use local_completiondoctor\tests\fake_site_check;

/**
 * Tests for the auditors and finding persistence.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_auditor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(site_auditor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(auditor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(finding_store::class)]
final class course_auditor_test extends \advanced_testcase {
    use check_testcase_trait;

    /** @var string[] Keys the fake check reports on the next run. */
    private array $keys = [];

    /**
     * Register the fake checks.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->register_checks([new fake_check(), new failing_check(), new fake_site_check()]);
        fake_check::$runner = function (audit_context $context, fake_check $check): array {
            return array_map(fn(string $key) => $check->emit((int) $context->course->id, $key), $this->keys);
        };
    }

    /**
     * Reset the registry.
     */
    protected function tearDown(): void {
        $this->reset_checks();
        parent::tearDown();
    }

    /**
     * Status of the stored finding with a key.
     *
     * @param int $courseid
     * @param string $key
     * @return string|null
     */
    private function status_of(int $courseid, string $key): ?string {
        foreach (finding_store::get_course_findings($courseid, null) as $finding) {
            if ($finding->checkid === 'fakecheck' && $finding->identity['key'] === $key) {
                return $finding->status;
            }
        }
        return null;
    }

    /**
     * The full lifecycle: new, kept, resolved, reopened, ignored kept across audits.
     */
    public function test_lifecycle(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->keys = ['a', 'b'];
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(['new' => 2, 'updated' => 0, 'reopened' => 0, 'resolved' => 0], $result->stats);
        $this->assertSame(finding::STATUS_OPEN, $this->status_of($course->id, 'a'));

        // The same findings again: updated, not duplicated.
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(2, $result->stats['updated']);
        $this->assertCount(2, finding_store::get_course_findings($course->id, null));

        // Ignore b, then b disappears and a stays: b stays ignored, a open.
        $b = array_values(array_filter(
            finding_store::get_course_findings($course->id),
            fn($f) => $f->identity['key'] === 'b'
        ))[0];
        finding_store::ignore($b->id, 'Known', 2);
        $this->keys = ['a'];
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(0, $result->stats['resolved']);
        $this->assertSame(finding::STATUS_IGNORED, $this->status_of($course->id, 'b'));

        // Key a disappears: resolved. Then it comes back: reopened.
        $this->keys = [];
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(1, $result->stats['resolved']);
        $this->assertSame(finding::STATUS_RESOLVED, $this->status_of($course->id, 'a'));
        $this->keys = ['a', 'b'];
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(1, $result->stats['reopened']);
        $this->assertSame(finding::STATUS_OPEN, $this->status_of($course->id, 'a'));
        $this->assertSame(finding::STATUS_IGNORED, $this->status_of($course->id, 'b'));
    }

    /**
     * A dry run writes nothing.
     */
    public function test_dry_run_writes_nothing(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->keys = ['a'];
        $result = course_auditor::audit($course->id, false, ['fakecheck']);
        $this->assertCount(1, $result->findings);
        $this->assertNull($result->stats);
        $this->assertSame(0, $DB->count_records(finding_store::TABLE));
    }

    /**
     * A failing check is reported, other checks still run, and its stored findings are left alone.
     */
    public function test_failing_check_is_isolated(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        // A finding of the failing check from an earlier audit.
        $old = new finding('failingcheck', (int) $course->id, 2, 'x', [], [], null, false, ['key' => 'old']);
        finding_store::sync((int) $course->id, [$old], ['failingcheck']);

        $this->keys = ['a'];
        $result = course_auditor::audit($course->id, true, ['fakecheck', 'failingcheck']);
        $this->assertDebuggingCalled();
        $this->assertArrayHasKey('failingcheck', $result->errors);
        $this->assertSame(['fakecheck'], $result->checksrun);
        $this->assertCount(1, $result->findings);
        $this->assertSame(
            finding::STATUS_OPEN,
            $DB->get_field(finding_store::TABLE, 'status', ['checkid' => 'failingcheck'])
        );
    }

    /**
     * Disabled checks do not run, and their open findings are resolved so counts drop.
     */
    public function test_disabled_check_not_run(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->keys = ['a'];
        course_auditor::audit($course->id, true, ['fakecheck']);

        set_config('enabled_fakecheck', 0, 'local_completiondoctor');
        $this->keys = [];
        $result = course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame([], $result->checksrun);
        $this->assertSame(finding::STATUS_RESOLVED, $this->status_of($course->id, 'a'));
    }

    /**
     * The summary counts open findings by severity and is invalidated by audits.
     */
    public function test_summary_cache(): void {
        $course = $this->getDataGenerator()->create_course();
        fake_check::$runner = fn(audit_context $c, fake_check $check) => [
            $check->emit((int) $c->course->id, 'a', null, base_check::SEVERITY_CRITICAL),
            $check->emit((int) $c->course->id, 'b', null, base_check::SEVERITY_INFO),
        ];
        course_auditor::audit($course->id, true, ['fakecheck']);
        $summary = finding_store::get_summary((int) $course->id);
        $this->assertSame(1, $summary['critical']);
        $this->assertSame(0, $summary['warning']);
        $this->assertSame(1, $summary['info']);
        $this->assertSame(2, $summary['total']);

        fake_check::$runner = null;
        course_auditor::audit($course->id, true, ['fakecheck']);
        $this->assertSame(0, finding_store::get_summary((int) $course->id)['total']);
    }

    /**
     * Site checks are stored against the site course and are not run by course audits.
     */
    public function test_site_auditor(): void {
        $course = $this->getDataGenerator()->create_course();
        $result = course_auditor::audit($course->id, true, ['fakesitecheck']);
        $this->assertSame([], $result->checksrun);

        $result = site_auditor::audit(true, ['fakesitecheck']);
        $this->assertCount(1, $result->findings);
        $this->assertCount(1, finding_store::get_course_findings((int) SITEID));
    }

    /**
     * Course deletion removes the findings.
     */
    public function test_course_deleted_cleans_up(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->keys = ['a'];
        course_auditor::audit($course->id, true, ['fakecheck']);
        delete_course($course, false);
        $this->assertSame([], finding_store::get_course_findings((int) $course->id, null));
    }
}
