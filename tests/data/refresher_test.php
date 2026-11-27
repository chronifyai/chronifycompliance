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

namespace local_chronifycompliance\data;

use local_chronifycompliance\task\cleanup;
use local_chronifycompliance\task\refresh_dataset;
use local_chronifycompliance\task\refresh_datasets;
use local_chronifycompliance\tests\fake_dataset;

#[\PHPUnit\Framework\Attributes\CoversClass(refresher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dataset_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(refresh_datasets::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(refresh_dataset::class)]
/**
 * Tests for the data layer refresh: incremental batches, time and batch limits, pause, locks,
 * failures and resuming, and the scheduled and ad hoc tasks.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\data\refresher
 * @covers \local_chronifycompliance\data\dataset_registry
 * @covers \local_chronifycompliance\task\refresh_datasets
 * @covers \local_chronifycompliance\task\refresh_dataset
 */
final class refresher_test extends \advanced_testcase {
    /** @var fake_dataset */
    private fake_dataset $dataset;

    /**
     * Fresh fake dataset and fact table, no pause between batches.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The refresher needs real transactions: a failed batch must really be rolled back.
        $this->preventResetByRollback();
        fake_dataset::create_table();
        $this->dataset = new fake_dataset();
        dataset_registry::phpunit_set([$this->dataset]);
        set_config('refreshpace', 0, 'local_chronifycompliance');
        set_config('refreshhours', '', 'local_chronifycompliance');
    }

    /**
     * Drop the fact table and forget the fake registry.
     */
    protected function tearDown(): void {
        fake_dataset::drop_table();
        dataset_registry::phpunit_set(null);
        parent::tearDown();
    }

    /**
     * Number of stored fact rows.
     *
     * @return int
     */
    private function stored(): int {
        global $DB;
        return $DB->count_records(fake_dataset::TABLE);
    }

    /**
     * A refresh reads everything in batches; later refreshes read only new rows.
     */
    public function test_incremental(): void {
        global $DB;
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $users = $DB->count_records('user');

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 3]);
        $this->assertSame(refresher::RESULT_DONE, $outcome->result);
        $this->assertSame($users, $outcome->rows);
        $this->assertSame((int) ceil($users / 3), $outcome->batches);
        $this->assertSame($users, $this->stored());
        $state = refresher::get_state('fake');
        $this->assertSame(refresher::STATUS_IDLE, $state->status);
        $this->assertEquals($DB->get_field_sql('SELECT MAX(id) FROM {user}'), $state->highwater);
        $this->assertGreaterThan(0, (int) $state->timefinished);

        // Nothing new: nothing read.
        $outcome = refresher::refresh($this->dataset, ['batchsize' => 3]);
        $this->assertSame([refresher::RESULT_DONE, 0], [$outcome->result, $outcome->rows]);

        // Only the new rows.
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $outcome = refresher::refresh($this->dataset, ['batchsize' => 3]);
        $this->assertSame(2, $outcome->rows);
        $this->assertSame($users + 2, $this->stored());
    }

    /**
     * A refresh may not run inside a transaction: a failed batch could not be rolled back alone.
     */
    public function test_refuses_open_transaction(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            refresher::refresh($this->dataset);
            $this->fail('Expected a coding exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('transaction', $e->getMessage());
        }
        $transaction->allow_commit();
    }

    /**
     * A refresh out of batches stops as queued and the next one goes on from there.
     */
    public function test_batch_limit_continues(): void {
        global $DB;
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $users = $DB->count_records('user');

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2, 'maxbatches' => 1]);
        $this->assertSame([refresher::RESULT_MORE, 2], [$outcome->result, $outcome->rows]);
        $this->assertSame(refresher::STATUS_QUEUED, refresher::get_state('fake')->status);
        $this->assertTrue(refresher::is_due('fake', DAYSECS));

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2]);
        $this->assertSame(refresher::RESULT_DONE, $outcome->result);
        $this->assertSame($users, $this->stored());
        // The counters cover the whole refresh, across both runs.
        $this->assertEquals($users, refresher::get_state('fake')->rowsread);
        $this->assertFalse(refresher::is_due('fake', DAYSECS));
        $this->assertTrue(refresher::is_due('fake', DAYSECS, time() + DAYSECS));
    }

    /**
     * A failed batch is rolled back; the next refresh resumes after the last saved batch, with no duplicates.
     */
    public function test_failure_resumes_without_duplicates(): void {
        global $DB;
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $users = $DB->count_records('user');
        $this->dataset->failonbatch = 2;

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2]);
        $this->assertSame(refresher::RESULT_FAILED, $outcome->result);
        $this->assertSame('Simulated failure', $outcome->error);
        $this->assertSame(2, $this->stored(), 'Only the first batch is kept.');
        $state = refresher::get_state('fake');
        $this->assertSame(refresher::STATUS_FAILED, $state->status);
        $this->assertEquals(1, $state->failures);
        $this->assertSame('Simulated failure', $state->lasterror);
        $this->assertTrue(refresher::is_due('fake', DAYSECS));

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2]);
        $this->assertSame(refresher::RESULT_DONE, $outcome->result);
        $this->assertSame($users, $this->stored());
        $this->assertSame($users, (int) $DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {' . fake_dataset::TABLE . '}'));
        $state = refresher::get_state('fake');
        $this->assertEquals(0, $state->failures);
        $this->assertNull($state->lasterror);
    }

    /**
     * A refresh that died while running (status running, lock gone) goes on from its high water mark.
     */
    public function test_interrupted_refresh_resumes(): void {
        global $DB;
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_user();
        }
        refresher::refresh($this->dataset, ['batchsize' => 2, 'maxbatches' => 1]);
        $DB->set_field(refresher::TABLE, 'status', refresher::STATUS_RUNNING, ['dataset' => 'fake']);

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2]);
        $this->assertSame(refresher::RESULT_DONE, $outcome->result);
        $this->assertSame($DB->count_records('user'), $this->stored());
    }

    /**
     * Nothing runs while paused; a running refresh stops between batches when paused.
     */
    public function test_paused(): void {
        set_config('paused', 1, 'local_chronifycompliance');
        $outcome = refresher::refresh($this->dataset, ['batchsize' => 1]);
        $this->assertSame(refresher::RESULT_PAUSED, $outcome->result);
        $this->assertSame(0, $this->stored());
        $this->assertNull(refresher::get_state('fake'));
    }

    /**
     * Only one refresh of a dataset runs at a time.
     */
    public function test_locked(): void {
        global $CFG;
        // Database record locks, so a second lock in the same process is refused, as for another cron process.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $factory = \core\lock\lock_config::get_lock_factory('local_chronifycompliance');
        $lock = $factory->get_lock('refresh_fake', 0);
        try {
            $outcome = refresher::refresh($this->dataset);
            $this->assertSame(refresher::RESULT_LOCKED, $outcome->result);
            $this->assertSame(0, $this->stored());
        } finally {
            $lock->release();
        }
    }

    /**
     * Rows without a key above the high water mark make the refresh fail instead of looping.
     */
    public function test_bad_keys_fail(): void {
        $this->dataset->badkeys = true;
        $outcome = refresher::refresh($this->dataset, ['batchsize' => 2]);
        $this->assertSame(refresher::RESULT_FAILED, $outcome->result);
        $this->assertStringContainsString('without a key above', $outcome->error);
        $this->assertSame(0, $this->stored());
    }

    /**
     * Reset deletes what the dataset stored and its state; the next refresh loads from the start.
     */
    public function test_reset(): void {
        global $DB;
        refresher::refresh($this->dataset);
        $this->assertGreaterThan(0, $this->stored());
        refresher::reset($this->dataset);
        $this->assertTrue($this->dataset->purged);
        $this->assertSame(0, $this->stored());
        $this->assertNull(refresher::get_state('fake'));
        refresher::refresh($this->dataset);
        $this->assertSame($DB->count_records('user'), $this->stored());
    }

    /**
     * The scheduled task works only inside the refresh window and not while paused.
     */
    public function test_scheduled_task_window_and_pause(): void {
        $task = new refresh_datasets();
        $hour = (int) date('G');
        set_config('refreshhours', (($hour + 1) % 24) . '-' . (($hour + 2) % 24), 'local_chronifycompliance');
        $this->assertFalse(refresher::within_window());
        $this->expectOutputRegex('/Outside the refresh window/');
        $task->execute();
        $this->assertNull(refresher::get_state('fake'));

        set_config('refreshhours', $hour . '-' . (($hour + 1) % 24), 'local_chronifycompliance');
        $this->assertTrue(refresher::within_window());
        set_config('paused', 1, 'local_chronifycompliance');
        $task->execute();
        $this->assertNull(refresher::get_state('fake'));

        set_config('paused', 0, 'local_chronifycompliance');
        $task->execute();
        $this->assertSame(refresher::STATUS_IDLE, refresher::get_state('fake')->status);
        $this->assertGreaterThan(0, $this->stored());
    }

    /**
     * The scheduled task skips datasets that finished recently.
     */
    public function test_scheduled_task_skips_fresh_datasets(): void {
        refresher::refresh($this->dataset);
        $this->getDataGenerator()->create_user();
        $before = $this->stored();
        ob_start();
        (new refresh_datasets())->execute();
        ob_end_clean();
        $this->assertSame($before, $this->stored(), 'Finished less than MAX_AGE ago: not due.');
    }

    /**
     * Refresh now queues one ad hoc task; while paused, the task queues itself for later.
     */
    public function test_adhoc_task(): void {
        refresher::request('fake');
        refresher::request('fake');
        $tasks = \core\task\manager::get_adhoc_tasks(refresh_dataset::class);
        $this->assertCount(1, $tasks);

        set_config('paused', 1, 'local_chronifycompliance');
        $task = reset($tasks);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame(0, $this->stored());
        $later = array_filter(
            \core\task\manager::get_adhoc_tasks(refresh_dataset::class),
            fn($queued) => $queued->get_next_run_time() > time()
        );
        $this->assertCount(1, $later);

        set_config('paused', 0, 'local_chronifycompliance');
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertGreaterThan(0, $this->stored());

        // Unknown datasets are ignored.
        $unknown = new refresh_dataset();
        $unknown->set_custom_data(['dataset' => 'nosuchdataset']);
        $this->expectOutputRegex('/Unknown dataset/');
        $unknown->execute();
    }

    /**
     * The cleanup task removes the state of datasets that no longer exist.
     */
    public function test_cleanup_unknown_states(): void {
        global $DB;
        refresher::refresh($this->dataset);
        $DB->insert_record(refresher::TABLE, ['dataset' => 'gone', 'status' => 'idle', 'timemodified' => time()]);
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertFalse($DB->record_exists(refresher::TABLE, ['dataset' => 'gone']));
        $this->assertTrue($DB->record_exists(refresher::TABLE, ['dataset' => 'fake']));
    }
}
