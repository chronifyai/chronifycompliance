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

namespace local_chronifycompliance\task;

use local_chronifycompliance\data\dataset_registry;
use local_chronifycompliance\data\refresher;
use local_chronifycompliance\local\scan_manager;

/**
 * Ad hoc refresh of one dataset (Refresh now). Runs at any hour, but waits while scans are paused.
 *
 * Works for at most "refreshbudget" minutes, then queues itself again until the dataset is read.
 *
 * Custom data: dataset (dataset id).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_dataset extends \core\task\adhoc_task {
    /** @var int Seconds to wait before trying again while paused. */
    public const PAUSED_RETRY = 5 * MINSECS;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:refreshdataset', 'local_chronifycompliance');
    }

    /**
     * Refresh the dataset.
     */
    public function execute(): void {
        $id = (string) ($this->get_custom_data()->dataset ?? '');
        $dataset = dataset_registry::get_dataset($id);
        if (!$dataset) {
            mtrace("Unknown dataset '{$id}'.");
            return;
        }
        if (scan_manager::is_paused()) {
            $this->queue_again(time() + self::PAUSED_RETRY);
            mtrace('Scans and refreshes are paused; trying again later.');
            return;
        }
        $outcome = refresher::refresh($dataset, ['deadline' => time() + refresher::get_budget()]);
        mtrace("Dataset {$id}: {$outcome->result}, {$outcome->rows} rows in {$outcome->batches} batches."
            . ($outcome->error ? ' Error: ' . $outcome->error : ''));
        if ($outcome->result === refresher::RESULT_MORE) {
            $this->queue_again(time());
        } else if ($outcome->result === refresher::RESULT_PAUSED) {
            $this->queue_again(time() + self::PAUSED_RETRY);
        }
    }

    /**
     * Queue another run of this refresh.
     *
     * @param int $runat
     */
    private function queue_again(int $runat): void {
        $task = new self();
        $task->set_custom_data($this->get_custom_data());
        $task->set_next_run_time($runat);
        \core\task\manager::queue_adhoc_task($task);
    }
}
