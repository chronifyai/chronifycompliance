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

/**
 * Outcome of auditing one course (or the site).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_result {
    /** @var finding[] Findings detected. */
    public array $findings = [];
    /** @var string[] Ids of the checks that ran without error. */
    public array $checksrun = [];
    /** @var string[] Error messages keyed by check id. */
    public array $errors = [];
    /** @var array|null Persistence counts (new, updated, reopened, resolved), null when not saved. */
    public ?array $stats = null;
    /** @var float Duration in seconds. */
    public float $duration = 0.0;
    /** @var int Number of DB queries (reads and writes). */
    public int $queries = 0;
    /** @var int Peak memory in bytes during the audit (process peak on PHP < 8.2). */
    public int $peakmemory = 0;
    /** @var int SCORM packages of the course not analyzed yet, so their checks could not run. */
    public int $waiting = 0;
    /** @var int Time the audit started; requests made before it are answered by this audit. */
    public int $timestarted;

    /**
     * Create a result.
     *
     * @param int $courseid Audited course (SITEID for the site).
     */
    public function __construct(
        /** @var int Audited course. */
        public int $courseid,
    ) {
        $this->timestarted = time();
    }

    /**
     * Count findings by severity.
     *
     * @return int[] Keyed by severity.
     */
    public function count_by_severity(): array {
        $counts = [base_check::SEVERITY_CRITICAL => 0, base_check::SEVERITY_WARNING => 0, base_check::SEVERITY_INFO => 0];
        foreach ($this->findings as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }
        return $counts;
    }
}
