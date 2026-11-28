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

namespace local_chronifycompliance\check\site;

use core\check\result;
use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\core_check_adapter;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_auditor;
use local_chronifycompliance\local\finding_store;

#[\PHPUnit\Framework\Attributes\CoversClass(completiontasks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cronstale::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(completiondisabled::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(core_check_adapter::class)]
/**
 * Tests of the site checks and their Check API integration.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\site\completiontasks
 * @covers \local_chronifycompliance\check\site\cronstale
 * @covers \local_chronifycompliance\check\site\completiondisabled
 * @covers \local_chronifycompliance\check\core_check_adapter
 */
final class site_checks_test extends \advanced_testcase {
    /**
     * Prepare a site where everything is healthy.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        check_registry::reset();
        set_config('enablecompletion', 1);
        set_config('cron_enabled', 1);
        set_config('lastcronstart', time() - 60, 'tool_task');
        foreach (['completion_regular_task', 'completion_daily_task'] as $name) {
            $this->set_task('\\core\\task\\' . $name, false, time() - 60);
        }
    }

    /**
     * Configure a core task.
     *
     * @param string $classname
     * @param bool $disabled
     * @param int $lastrun
     * @param int $faildelay
     */
    private function set_task(string $classname, bool $disabled, int $lastrun, int $faildelay = 0): void {
        global $DB;
        $task = \core\task\manager::get_scheduled_task($classname);
        $task->set_disabled($disabled);
        \core\task\manager::configure_scheduled_task($task);
        // Run state is not saved by configure_scheduled_task().
        $DB->set_field('task_scheduled', 'lastruntime', $lastrun, ['classname' => $classname]);
        $DB->set_field('task_scheduled', 'faildelay', $faildelay, ['classname' => $classname]);
    }

    /**
     * Run a site check.
     *
     * @param string $id
     * @return finding[]
     */
    private function run_check(string $id): array {
        return check_registry::get_check($id)->run(new audit_context(get_site()));
    }

    /**
     * Healthy site: no findings.
     */
    public function test_healthy_site(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->assertSame([], $this->run_check('completiontasks'));
        $this->assertSame([], $this->run_check('cronstale'));
        $this->assertSame([], $this->run_check('completiondisabled'));
    }

    /**
     * Disabled, stale, never run and failing completion tasks.
     */
    public function test_completiontasks(): void {
        $regular = '\\core\\task\\completion_regular_task';
        $this->set_task($regular, true, time() - 60);
        $findings = $this->run_check('completiontasks');
        $this->assertCount(1, $findings);
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertSame('check:completiontasks:messagedisabled', $findings[0]->messagekey);

        $this->set_task($regular, false, time() - 2 * HOURSECS);
        $findings = $this->run_check('completiontasks');
        $this->assertSame('check:completiontasks:messagestale', $findings[0]->messagekey);
        $this->assertSame(base_check::SEVERITY_WARNING, $findings[0]->severity);

        set_config('completiontaskmaxage', 180, 'local_chronifycompliance');
        $this->assertSame([], $this->run_check('completiontasks'));

        $this->set_task($regular, false, time() - 60, 120);
        $this->assertSame('check:completiontasks:messagefailing', $this->run_check('completiontasks')[0]->messagekey);

        $this->set_task($regular, false, 0);
        $this->assertSame('check:completiontasks:messagenever', $this->run_check('completiontasks')[0]->messagekey);

        $this->set_task($regular, false, time() - 60);
        $this->set_task('\\core\\task\\completion_daily_task', false, time() - 2 * DAYSECS);
        $findings = $this->run_check('completiontasks');
        $this->assertSame('\\core\\task\\completion_daily_task', $findings[0]->identity['task']);

        // Not relevant when completion is off on the site (completiondisabled covers that).
        set_config('enablecompletion', 0);
        $this->assertSame([], $this->run_check('completiontasks'));
    }

    /**
     * Cron disabled, never run and stale.
     */
    public function test_cronstale(): void {
        set_config('lastcronstart', time() - HOURSECS, 'tool_task');
        $this->assertSame('check:cronstale:message', $this->run_check('cronstale')[0]->messagekey);
        set_config('cronmaxage', 120, 'local_chronifycompliance');
        $this->assertSame([], $this->run_check('cronstale'));

        unset_config('lastcronstart', 'tool_task');
        $this->assertSame('check:cronstale:messagenever', $this->run_check('cronstale')[0]->messagekey);

        set_config('cron_enabled', 0);
        $this->assertSame('check:cronstale:messagedisabled', $this->run_check('cronstale')[0]->messagekey);
    }

    /**
     * Completion disabled on the site while courses use it.
     */
    public function test_completiondisabled(): void {
        set_config('enablecompletion', 0);
        $this->assertSame([], $this->run_check('completiondisabled'));
        $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $findings = $this->run_check('completiondisabled');
        $this->assertSame(base_check::SEVERITY_CRITICAL, $findings[0]->severity);
        $this->assertStringContainsString('1 courses have completion tracking on', $findings[0]->get_message());
    }

    /**
     * Site findings are stored against the site course.
     */
    public function test_site_audit_saves(): void {
        set_config('cron_enabled', 0);
        // Moodle's own checks have their own test (core_import_check_test).
        set_config('enabled_coreperformance', 0, 'local_chronifycompliance');
        foreach (\local_chronifycompliance\check\check_registry::get_checks(null, false, ['security']) as $id => $check) {
            set_config('enabled_' . $id, 0, 'local_chronifycompliance');
        }
        site_auditor::audit(true);
        $findings = finding_store::get_course_findings((int) SITEID);
        $this->assertSame(['cronstale'], array_values(array_unique(array_map(fn($f) => $f->checkid, $findings))));
    }

    /**
     * The checks appear in the core status checks with the right results.
     */
    public function test_core_check_api(): void {
        $checks = [];
        foreach (\core\check\manager::get_checks('status') as $check) {
            $checks[$check->get_ref()] = $check;
        }
        foreach (['completiontasks', 'cronstale', 'completiondisabled'] as $id) {
            $this->assertArrayHasKey('local_chronifycompliance_' . $id, $checks);
        }
        $this->assertSame(result::OK, $checks['local_chronifycompliance_cronstale']->get_result()->get_status());

        set_config('cron_enabled', 0);
        $this->assertSame(result::CRITICAL, $checks['local_chronifycompliance_cronstale']->get_result()->get_status());

        set_config('enabled_cronstale', 0, 'local_chronifycompliance');
        $this->assertSame(result::NA, $checks['local_chronifycompliance_cronstale']->get_result()->get_status());
    }
}
