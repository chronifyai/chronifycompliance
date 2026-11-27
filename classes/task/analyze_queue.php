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

use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\scorm\package_analyzer;

/**
 * Scheduled task analyzing queued SCORM packages a few at a time.
 *
 * Big packages found during audits are queued instead of analyzed inline. This task analyzes up to
 * "packagesperrun" of them per run, only within "packagehours" when set, and not while scans are
 * paused. Courses using an analyzed package are queued for a re-audit so their SCORM findings appear.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_queue extends \core\task\scheduled_task {
    /** @var int Default packages analyzed per run. */
    public const DEFAULT_PER_RUN = 10;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:analyzequeue', 'local_chronifycompliance');
    }

    /**
     * Analyze the next queued packages.
     */
    public function execute(): void {
        global $DB;
        if (scan_manager::is_paused()) {
            mtrace('Chronify.Compliance scans are paused.');
            return;
        }
        if (!self::within_allowed_hours((string) get_config('local_chronifycompliance', 'packagehours'))) {
            mtrace('Outside the allowed hours for package analysis.');
            return;
        }
        $limit = (int) get_config('local_chronifycompliance', 'packagesperrun') ?: self::DEFAULT_PER_RUN;
        // Least tried first, so a package that keeps failing never blocks the others.
        $queued = $DB->get_records(package_analyzer::TABLE, ['status' => package_analyzer::STATUS_QUEUED,
            'parserversion' => package_analyzer::PARSER_VERSION], 'attempts, id', 'id, contenthash, filesize, attempts', 0, $limit);
        foreach ($queued as $row) {
            if ((int) $row->attempts >= package_analyzer::MAX_ATTEMPTS) {
                package_analyzer::give_up($row->contenthash, (int) $row->filesize);
                mtrace('Package ' . substr($row->contenthash, 0, 8) . ': given up after ' . $row->attempts . ' attempts');
                continue;
            }
            $file = package_analyzer::get_file_by_hash($row->contenthash);
            if (!$file) {
                $DB->delete_records(package_analyzer::TABLE, ['id' => $row->id]);
                continue;
            }
            $start = microtime(true);
            $analysis = package_analyzer::analyze_stored_file($file);
            if ($analysis['status'] === package_analyzer::STATUS_PENDING) {
                mtrace('Package ' . substr($row->contenthash, 0, 8) . ': being analyzed by another process');
                continue;
            }
            foreach (package_analyzer::get_courses_using($row->contenthash) as $courseid) {
                scan_manager::queue_course($courseid);
            }
            mtrace(sprintf(
                'Package %s: %s, %.1f s',
                substr($row->contenthash, 0, 8),
                $analysis['status'],
                microtime(true) - $start
            ));
        }
    }

    /**
     * Whether the current hour is in a range like "0-6" or "22-5" (empty means always).
     *
     * @param string $range
     * @param int|null $hour Hour to test, default now (server time zone).
     * @return bool
     */
    public static function within_allowed_hours(string $range, ?int $hour = null): bool {
        if (!preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $range, $matches)) {
            return true;
        }
        $hour = $hour ?? (int) date('G');
        [$from, $to] = [(int) $matches[1], (int) $matches[2]];
        return $from <= $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }
}
