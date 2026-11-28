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
 * Scheduled refresh of the data layer.
 *
 * Runs every 15 minutes but only does work inside the refresh window ("refreshhours"), not while scans
 * are paused, and for at most "refreshbudget" minutes per run. Refreshes every dataset that has never
 * finished, has more to read, failed, or last finished more than MAX_AGE ago. A big first load is
 * spread over as many runs and nights as it needs.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_datasets extends \core\task\scheduled_task {
    /** @var int A dataset finished this long ago is refreshed again. */
    public const MAX_AGE = 20 * HOURSECS;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:refreshdatasets', 'local_chronifycompliance');
    }

    /**
     * Refresh the datasets that are due.
     */
    public function execute(): void {
        if (scan_manager::is_paused()) {
            mtrace('Scans and refreshes are paused.');
            return;
        }
        if (!refresher::within_window()) {
            mtrace('Outside the refresh window.');
            return;
        }
        $deadline = time() + refresher::get_budget();
        foreach (dataset_registry::get_datasets() as $id => $dataset) {
            if (time() >= $deadline) {
                mtrace('Refresh time budget used; the rest continues on the next run.');
                return;
            }
            if (!refresher::is_due($id, self::MAX_AGE)) {
                continue;
            }
            $outcome = refresher::refresh($dataset, ['deadline' => $deadline]);
            mtrace("Dataset {$id}: {$outcome->result}, {$outcome->rows} rows in {$outcome->batches} batches."
                . ($outcome->error ? ' Error: ' . $outcome->error : ''));
            if ($outcome->result === refresher::RESULT_PAUSED) {
                return;
            }
        }
    }
}
