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

use local_completiondoctor\links\link_checker;
use local_completiondoctor\links\link_collector;
use local_completiondoctor\links\link_scan;
use local_completiondoctor\links\link_store;
use local_completiondoctor\local\scan_manager;

/**
 * Checks the links of one course, on request from the course report.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_links extends \core\task\adhoc_task {
    /** @var int Progress is saved after this many links. */
    private const PROGRESS_STEP = 10;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:checklinks', 'local_completiondoctor');
    }

    /**
     * One link check at a time, so the site never sends many outside requests at once.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Collect and check the links, then replace the stored result of the course.
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        $courseid = (int) $data->courseid;
        $linkcheckid = (int) $data->linkcheckid;

        if (scan_manager::is_paused()) {
            $retry = new self();
            $retry->set_custom_data($data);
            $retry->set_next_run_time(time() + scan_manager::PAUSE_RETRY);
            \core\task\manager::queue_adhoc_task($retry);
            mtrace('Completion Doctor scans are paused, link check postponed.');
            return;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            link_scan::update($linkcheckid, ['status' => link_scan::STATUS_CANCELLED, 'timefinished' => time()]);
            return;
        }

        try {
            link_scan::update($linkcheckid, ['status' => link_scan::STATUS_RUNNING, 'timestarted' => time()]);
            $collector = link_collector::from_config();
            $occurrences = $collector->collect($course);

            $unique = [];
            foreach ($occurrences as $index => $occurrence) {
                $key = self::key($occurrence);
                $unique[$key] ??= [$occurrence['url'], $occurrence['area']];
                $occurrences[$index]['key'] = $key;
            }
            link_scan::update($linkcheckid, ['totalitems' => count($unique), 'processed' => 0]);

            $checker = link_checker::from_config();
            $results = [];
            $done = 0;
            foreach ($unique as $key => [$url, $area]) {
                $results[$key] = $checker->check($url, $area);
                if (++$done % self::PROGRESS_STEP === 0) {
                    link_scan::update($linkcheckid, ['processed' => $done]);
                }
            }

            $rows = [];
            foreach ($occurrences as $occurrence) {
                $result = $results[$occurrence['key']];
                $rows[] = [
                    'cmid' => $occurrence['cmid'],
                    'source' => $occurrence['source'],
                    'itemid' => $occurrence['itemid'],
                    'sourcename' => $occurrence['sourcename'],
                    'url' => $occurrence['url'],
                    'linktext' => $occurrence['linktext'],
                    'urlhash' => sha1($occurrence['url']),
                    'kind' => $result['kind'],
                    'status' => $result['status'],
                    'httpcode' => $result['httpcode'],
                    'reason' => $result['reason'],
                    'timechecked' => $result['timechecked'],
                ];
            }
            link_store::replace_course($courseid, $rows);

            $broken = count(array_filter($results, fn($r) => $r['status'] === link_checker::STATUS_BROKEN));
            link_scan::update($linkcheckid, [
                'status' => link_scan::STATUS_FINISHED,
                'processed' => $done,
                'skippedfiles' => $collector->get_skipped_files(),
                'truncated' => (int) $collector->is_truncated(),
                'timefinished' => time(),
            ]);
            mtrace("Course {$courseid}: " . count($occurrences) . " links, {$done} checked, {$broken} broken.");
        } catch (\Throwable $e) {
            link_scan::update($linkcheckid, ['status' => link_scan::STATUS_FAILED, 'timefinished' => time()]);
            mtrace("Course {$courseid}: link check failed: " . $e->getMessage());
        }
    }

    /**
     * Key of a link to check: embedded files depend on the text's file area.
     *
     * @param array $occurrence
     * @return string
     */
    private static function key(array $occurrence): string {
        if (str_starts_with($occurrence['url'], '@@PLUGINFILE@@/')) {
            return $occurrence['url'] . '|' . json_encode($occurrence['area']);
        }
        return $occurrence['url'];
    }
}
