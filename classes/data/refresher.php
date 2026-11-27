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

use local_chronifycompliance\local\scan_manager;
use stdClass;

/**
 * Runs dataset refreshes: incremental, in paced batches, within a time budget, one at a time per dataset.
 *
 * Each batch and the new high water mark are saved in one transaction. A refresh that dies (an error,
 * a killed cron process) keeps every batch saved before it, and the next refresh goes on from there.
 * A refresh that runs out of time or batches stops with status "queued" and continues on the next run.
 * Nothing runs while an administrator has paused scans (load control).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresher {
    /** @var string Table holding the state of each dataset. */
    public const TABLE = 'local_chronifycompliance_refresh';

    /** @var string Nothing to read (status of a dataset). */
    public const STATUS_IDLE = 'idle';
    /** @var string Started, more to read on the next run. */
    public const STATUS_QUEUED = 'queued';
    /** @var string A refresh is running (or died while running). */
    public const STATUS_RUNNING = 'running';
    /** @var string The last refresh failed. */
    public const STATUS_FAILED = 'failed';

    /** @var string Everything was read (outcome of a refresh). */
    public const RESULT_DONE = 'done';
    /** @var string Out of time or batches; more to read. */
    public const RESULT_MORE = 'more';
    /** @var string Scans and refreshes are paused. */
    public const RESULT_PAUSED = 'paused';
    /** @var string Another refresh of the dataset is running. */
    public const RESULT_LOCKED = 'locked';
    /** @var string The refresh failed. */
    public const RESULT_FAILED = 'failed';

    /** @var int Default number of source rows per batch. */
    public const DEFAULT_BATCH = 50000;
    /** @var int Default seconds between two batches. */
    public const DEFAULT_PACE = 5;
    /** @var int Default minutes a scheduled run may spend. */
    public const DEFAULT_BUDGET = 10;
    /** @var string Default hours scheduled refreshes may run in (server time). */
    public const DEFAULT_HOURS = '1-5';

    /**
     * Refresh a dataset.
     *
     * @param dataset $dataset
     * @param array $options Optional: 'deadline' (unix time to stop at), 'maxbatches', 'batchsize', 'pace' (seconds).
     * @return stdClass With 'result' (a RESULT_* constant), 'rows' and 'batches' read by this call, and 'error'.
     * @throws \coding_exception When called inside a database transaction.
     */
    public static function refresh(dataset $dataset, array $options = []): stdClass {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('A dataset refresh cannot run inside a database transaction');
        }
        $outcome = (object) ['result' => self::RESULT_DONE, 'rows' => 0, 'batches' => 0, 'error' => null];
        if (scan_manager::is_paused()) {
            $outcome->result = self::RESULT_PAUSED;
            return $outcome;
        }

        $factory = \core\lock\lock_config::get_lock_factory('local_chronifycompliance');
        $lock = $factory->get_lock('refresh_' . $dataset->get_id(), 0);
        if (!$lock) {
            $outcome->result = self::RESULT_LOCKED;
            return $outcome;
        }

        try {
            self::run($dataset, $options, $outcome);
        } finally {
            $lock->release();
        }
        return $outcome;
    }

    /**
     * Read and store batches until done, out of time or batches, paused, or failed.
     *
     * @param dataset $dataset
     * @param array $options See refresh().
     * @param stdClass $outcome Updated in place.
     */
    private static function run(dataset $dataset, array $options, stdClass $outcome): void {
        global $DB;

        $batchsize = max(1, (int) ($options['batchsize'] ?? self::get_batch_size()));
        $pace = max(0, (int) ($options['pace'] ?? self::get_pace()));
        $deadline = $options['deadline'] ?? null;
        $maxbatches = $options['maxbatches'] ?? null;

        $state = self::get_or_create_state($dataset);
        if (!in_array($state->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            // A new refresh: counters start again. A queued or interrupted one goes on counting.
            $state->rowsread = 0;
            $state->batches = 0;
            $state->runtime = 0;
            $state->timestarted = time();
        }
        $state->status = self::STATUS_RUNNING;
        self::save_state($state);

        try {
            while (true) {
                if ($maxbatches !== null && $outcome->batches >= $maxbatches) {
                    self::stop($state, self::STATUS_QUEUED, $outcome, self::RESULT_MORE);
                    return;
                }
                if ($deadline !== null && time() >= $deadline) {
                    self::stop($state, self::STATUS_QUEUED, $outcome, self::RESULT_MORE);
                    return;
                }
                if ($outcome->batches > 0 && scan_manager::is_paused()) {
                    self::stop($state, self::STATUS_QUEUED, $outcome, self::RESULT_PAUSED);
                    return;
                }

                $start = microtime(true);
                $rows = $dataset->read((int) $state->highwater, $batchsize);
                if (!$rows) {
                    self::finish($state);
                    return;
                }
                $highwater = self::check_keys($dataset, $rows, (int) $state->highwater);

                $transaction = $DB->start_delegated_transaction();
                try {
                    $dataset->store($rows);
                    $state->highwater = $highwater;
                    $state->rowsread += count($rows);
                    $state->batches++;
                    $state->runtime += (int) round(microtime(true) - $start);
                    self::save_state($state);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    // Rolls the batch back and throws $e again.
                    $transaction->rollback($e);
                }

                $outcome->rows += count($rows);
                $outcome->batches++;

                if (count($rows) < $batchsize) {
                    // A short batch means the source has nothing more right now.
                    self::finish($state);
                    return;
                }
                if ($pace > 0) {
                    sleep($pace);
                }
            }
        } catch (\Throwable $e) {
            // The failed batch was rolled back; every batch before it stays saved.
            $state = $DB->get_record(self::TABLE, ['dataset' => $dataset->get_id()], '*', MUST_EXIST);
            $state->status = self::STATUS_FAILED;
            $state->failures++;
            $state->lasterror = \core_text::substr($e->getMessage(), 0, 1000);
            self::save_state($state);
            $outcome->result = self::RESULT_FAILED;
            $outcome->error = $e->getMessage();
        }
    }

    /**
     * Make sure every row has an integer key above the high water mark, and return the highest key.
     *
     * @param dataset $dataset
     * @param stdClass[] $rows
     * @param int $after Current high water mark.
     * @return int
     */
    private static function check_keys(dataset $dataset, array $rows, int $after): int {
        $highest = $after;
        foreach ($rows as $row) {
            if (!isset($row->key) || (int) $row->key <= $after) {
                throw new \coding_exception('Dataset ' . $dataset->get_id() . ' returned a row without a key above ' . $after);
            }
            $highest = max($highest, (int) $row->key);
        }
        return $highest;
    }

    /**
     * Mark the refresh as complete.
     *
     * @param stdClass $state
     */
    private static function finish(stdClass $state): void {
        $state->status = self::STATUS_IDLE;
        $state->failures = 0;
        $state->lasterror = null;
        $state->timefinished = time();
        self::save_state($state);
    }

    /**
     * Stop before reading everything.
     *
     * @param stdClass $state
     * @param string $status
     * @param stdClass $outcome
     * @param string $result
     */
    private static function stop(stdClass $state, string $status, stdClass $outcome, string $result): void {
        $state->status = $status;
        self::save_state($state);
        $outcome->result = $result;
    }

    /**
     * State of a dataset, created on first use with the dataset's start position.
     *
     * @param dataset $dataset
     * @return stdClass
     */
    private static function get_or_create_state(dataset $dataset): stdClass {
        global $DB;
        $state = $DB->get_record(self::TABLE, ['dataset' => $dataset->get_id()]);
        if ($state) {
            return $state;
        }
        $state = (object) [
            'dataset' => $dataset->get_id(),
            'status' => self::STATUS_IDLE,
            'highwater' => $dataset->get_start(),
            'rowsread' => 0,
            'batches' => 0,
            'runtime' => 0,
            'failures' => 0,
            'lasterror' => null,
            'timestarted' => 0,
            'timefinished' => 0,
            'timemodified' => time(),
        ];
        $state->id = $DB->insert_record(self::TABLE, $state);
        return $state;
    }

    /**
     * Save the state of a dataset.
     *
     * @param stdClass $state
     */
    private static function save_state(stdClass $state): void {
        global $DB;
        $state->timemodified = time();
        $DB->update_record(self::TABLE, $state);
    }

    /**
     * State of a dataset, or null before its first refresh.
     *
     * @param string $datasetid
     * @return stdClass|null
     */
    public static function get_state(string $datasetid): ?stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['dataset' => $datasetid]) ?: null;
    }

    /**
     * Whether a dataset should be refreshed by the next scheduled run: never finished, unfinished,
     * failed, or last finished more than $maxage seconds ago.
     *
     * @param string $datasetid
     * @param int $maxage Seconds.
     * @param int|null $now
     * @return bool
     */
    public static function is_due(string $datasetid, int $maxage, ?int $now = null): bool {
        $state = self::get_state($datasetid);
        if (!$state || $state->status !== self::STATUS_IDLE) {
            return true;
        }
        return (int) $state->timefinished <= ($now ?? time()) - $maxage;
    }

    /**
     * Start loading a dataset again from its start position. Deletes what it stored.
     *
     * @param dataset $dataset
     */
    public static function reset(dataset $dataset): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $dataset->purge();
        $DB->delete_records(self::TABLE, ['dataset' => $dataset->get_id()]);
        $transaction->allow_commit();
    }

    /**
     * Queue an ad hoc refresh of a dataset now (Refresh now). Runs outside the refresh window.
     *
     * @param string $datasetid
     */
    public static function request(string $datasetid): void {
        $task = new \local_chronifycompliance\task\refresh_dataset();
        $task->set_custom_data(['dataset' => $datasetid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Source rows per batch (setting).
     *
     * @return int
     */
    public static function get_batch_size(): int {
        $value = (int) get_config('local_chronifycompliance', 'refreshbatch');
        return $value > 0 ? $value : self::DEFAULT_BATCH;
    }

    /**
     * Seconds between batches (setting).
     *
     * @return int
     */
    public static function get_pace(): int {
        $value = get_config('local_chronifycompliance', 'refreshpace');
        return $value === false || $value === '' ? self::DEFAULT_PACE : max(0, (int) $value);
    }

    /**
     * Seconds a scheduled run may spend (setting, in minutes).
     *
     * @return int
     */
    public static function get_budget(): int {
        $value = (int) get_config('local_chronifycompliance', 'refreshbudget');
        return ($value > 0 ? $value : self::DEFAULT_BUDGET) * MINSECS;
    }

    /**
     * Whether scheduled refreshes may run now (setting "refreshhours", empty means any time).
     *
     * @param int|null $hour Hour to test, default now (server time zone).
     * @return bool
     */
    public static function within_window(?int $hour = null): bool {
        $range = get_config('local_chronifycompliance', 'refreshhours');
        if ($range === false) {
            $range = self::DEFAULT_HOURS;
        }
        return \local_chronifycompliance\task\analyze_queue::within_allowed_hours((string) $range, $hour);
    }

    /**
     * Whether Moodle reads from a read-only replica on this site (refresh reads then go there).
     *
     * @return bool
     */
    public static function uses_replica(): bool {
        global $CFG;
        return !empty($CFG->dboptions['readonly']);
    }
}
