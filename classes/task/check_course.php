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

namespace local_completiondoctor\task;

use local_completiondoctor\check\course_auditor;
use local_completiondoctor\local\scan_manager;
use local_completiondoctor\scorm\inspector;

/**
 * Checks one course in the background when someone asks for it ("Check again now", the first visit
 * of the course report, "Analyze now" and "Analyze again" for a SCORM package), so pages never do
 * the work themselves.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_course extends \core\task\adhoc_task {
    /** @var int Seconds after which a request that was never answered is forgotten. */
    public const REQUEST_EXPIRY = HOURSECS;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:checkcourse', 'local_completiondoctor');
    }

    /**
     * Two at a time: these answer a person waiting on a page, but never take all task runners.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 2;
    }

    /**
     * Queue a check of a course. An identical check that has not started yet is not duplicated; one
     * that is already running does not count, because it may have read the course before the request.
     *
     * @param int $courseid
     * @param int $cmid SCORM activity whose package is analyzed first (any size), 0 for none.
     * @param bool $refresh Analyze the package again even when it was analyzed before.
     */
    public static function request(int $courseid, int $cmid = 0, bool $refresh = false): void {
        global $DB;
        \local_completiondoctor\local\audit_store::request($courseid);
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'cmid' => $cmid, 'refresh' => $refresh]);
        $waiting = $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND timestarted IS NULL AND ' . $DB->sql_compare_text('customdata') . ' = '
                . $DB->sql_compare_text(':customdata'),
            ['classname' => '\\' . self::class, 'customdata' => $task->get_custom_data_as_string()]
        );
        if (!$waiting) {
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Analyze the package if asked, then audit and save.
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        $courseid = (int) $data->courseid;
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }
        if (scan_manager::is_paused()) {
            $retry = new self();
            $retry->set_custom_data($data);
            $retry->set_next_run_time(time() + scan_manager::PAUSE_RETRY);
            \core\task\manager::queue_adhoc_task($retry);
            mtrace('Completion Doctor scans are paused, course check postponed.');
            return;
        }
        if (!empty($data->cmid)) {
            $cm = get_fast_modinfo($courseid)->get_cms()[(int) $data->cmid] ?? null;
            if ($cm && $cm->modname === 'scorm') {
                inspector::inspect($cm, empty($data->refresh) ? inspector::MODE_NOW : inspector::MODE_REFRESH);
            }
        }
        $result = course_auditor::audit($courseid, true);
        mtrace(sprintf('Course %d: %d findings, %.2f s', $courseid, count($result->findings), $result->duration));
    }
}
