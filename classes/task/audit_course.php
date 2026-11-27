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

use local_chronifycompliance\check\course_auditor;
use local_chronifycompliance\local\scan_manager;

/**
 * Ad-hoc task auditing a batch of courses and storing the findings.
 *
 * Custom data: courseids (int[]), scanid (int|null), checkids (string[]|null).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_course extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:auditcourse', 'local_chronifycompliance');
    }

    /**
     * At most one batch runs at a time by default, so a scan never takes all the task runners.
     * Raise it with $CFG->task_concurrency_limit['local_chronifycompliance\\task\\audit_course'].
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Audit the courses of the batch.
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        $scanid = empty($data->scanid) ? null : (int) $data->scanid;
        $checkids = empty($data->checkids) ? null : (array) $data->checkids;

        if ($scanid && $this->is_cancelled($scanid)) {
            // Checked before the pause, so batches of a cancelled scan end instead of waiting.
            mtrace("Scan {$scanid} was cancelled or deleted, skipping the batch.");
            return;
        }
        if (scan_manager::is_paused()) {
            // Try again later with the same courses; the scan counters are unchanged.
            $retry = new self();
            $retry->set_custom_data($data);
            // Batches keep their spacing, so they do not all run back to back when the pause ends.
            $retry->set_next_run_time(time() + scan_manager::PAUSE_RETRY + (int) ($data->batch ?? 0) * scan_manager::get_pace());
            \core\task\manager::queue_adhoc_task($retry);
            if ($scanid) {
                scan_manager::touch($scanid);
            }
            mtrace('Chronify.Compliance scans are paused, batch postponed.');
            return;
        }
        $processed = 0;
        $failed = 0;
        foreach ((array) $data->courseids as $courseid) {
            if ($scanid && $this->is_cancelled($scanid)) {
                mtrace("Scan {$scanid} was cancelled or deleted, skipping the rest of the batch.");
                return;
            }
            $courseid = (int) $courseid;
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                // Deleted since the scan was queued.
                $processed++;
                continue;
            }
            try {
                $result = course_auditor::audit($courseid, true, $checkids);
                $processed++;
                mtrace(sprintf(
                    'Course %d: %d findings, %.2f s, %d queries%s',
                    $courseid,
                    count($result->findings),
                    $result->duration,
                    $result->queries,
                    $result->errors ? ', check errors: ' .
                    implode(',', array_keys($result->errors)) : ''
                ));
            } catch (\Throwable $e) {
                $failed++;
                mtrace("Course {$courseid}: audit failed: " . $e->getMessage());
            }
        }

        if ($scanid) {
            scan_manager::record_progress($scanid, $processed, $failed);
        }
    }

    /**
     * Whether the scan of this batch was cancelled or deleted (checked first and before each course).
     *
     * @param int $scanid
     * @return bool
     */
    private function is_cancelled(int $scanid): bool {
        global $DB;
        $status = $DB->get_field(scan_manager::TABLE, 'status', ['id' => $scanid]);
        return $status === false || $status === scan_manager::STATUS_CANCELLED;
    }
}
