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

namespace local_chronifycompliance\score;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use stdClass;

/**
 * The health score: 0 to 100 per pack and overall, computed from the stored findings.
 *
 * Pack score = 100 - min(100, 10 x critical + 3 x warning + 0.5 x info), counting open findings of enabled
 * checks; ignored findings count half. For the Completion and SCORM packs of the whole site, findings are
 * counted per 100 audited courses, so large sites are not punished for their size.
 *
 * The overall score is the average of the pack scores weighted by WEIGHTS, over the packs that have at
 * least one enabled check (so an empty pack neither helps nor hurts). Weights are fixed in code, not a
 * setting, so scores stay comparable between sites.
 *
 * Reads only the plugin's own tables.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_score {
    /** @var string Table holding the weekly snapshots. */
    public const TABLE = 'local_chronifycompliance_score';
    /** @var string Pack name used for the overall score in snapshots. */
    public const OVERALL = 'overall';

    /** @var int[] Weight of each pack in the overall score. */
    public const WEIGHTS = [
        base_check::PACK_SECURITY => 35,
        base_check::PACK_COMPLETION => 30,
        base_check::PACK_SITE => 20,
        base_check::PACK_SCORM => 15,
    ];
    /** @var float[] Points taken off per finding, by severity. */
    public const PENALTY = [
        base_check::SEVERITY_CRITICAL => 10,
        base_check::SEVERITY_WARNING => 3,
        base_check::SEVERITY_INFO => 0.5,
    ];
    /** @var float Ignored findings count this much of an open one. */
    public const IGNORED_FACTOR = 0.5;
    /** @var int Course findings are counted per this many audited courses (site scores). */
    public const PER_COURSES = 100;
    /** @var string[] Packs whose site score is counted per PER_COURSES courses. */
    public const COURSE_PACKS = [base_check::PACK_COMPLETION, base_check::PACK_SCORM];

    /** @var int Overall score from which the site is healthy. */
    public const HEALTHY = 80;
    /** @var int Overall score from which the site needs attention (below: at risk). */
    public const ATTENTION = 50;

    /**
     * Score of the whole site.
     *
     * @return stdClass With 'overall' (int|null), 'status' (healthy, attention, atrisk or null) and 'packs'
     *                  (pack => object with score, critical, warning, info), every pack with an enabled check.
     */
    public static function site(): stdClass {
        global $DB;
        $courses = $DB->count_records_select('local_chronifycompliance_audit', 'courseid <> :siteid', ['siteid' => SITEID]);
        $factor = self::PER_COURSES / max(self::PER_COURSES, $courses);
        return self::compute(null, $factor);
    }

    /**
     * Score of one course, from its own findings (Completion and SCORM packs).
     *
     * @param int $courseid
     * @return stdClass See site().
     */
    public static function course(int $courseid): stdClass {
        return self::compute($courseid, 1.0);
    }

    /**
     * Compute the scores.
     *
     * @param int|null $courseid One course, or null for the site.
     * @param float $factor Multiplier of course pack penalties (per 100 courses for the site).
     * @return stdClass
     */
    private static function compute(?int $courseid, float $factor): stdClass {
        global $DB;
        $packofcheck = [];
        foreach (check_registry::get_checks(null, true) as $id => $check) {
            if ($courseid !== null && $check->get_scope() === base_check::SCOPE_SITE) {
                continue;
            }
            $packofcheck[$id] = $check->get_pack();
        }
        $packs = [];
        foreach (base_check::get_packs() as $pack) {
            if (in_array($pack, $packofcheck, true)) {
                $packs[$pack] = (object) ['score' => 100, 'critical' => 0, 'warning' => 0, 'info' => 0, 'penalty' => 0.0];
            }
        }

        $where = 'status IN (:open, :ignored)';
        $params = ['open' => finding::STATUS_OPEN, 'ignored' => finding::STATUS_IGNORED];
        if ($courseid !== null) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $rows = $DB->get_recordset_sql(
            "SELECT checkid, severity, status, COUNT(1) AS total
               FROM {local_chronifycompliance_finding}
              WHERE {$where}
           GROUP BY checkid, severity, status",
            $params
        );
        $names = [base_check::SEVERITY_CRITICAL => 'critical', base_check::SEVERITY_WARNING => 'warning',
            base_check::SEVERITY_INFO => 'info'];
        foreach ($rows as $row) {
            $pack = $packofcheck[$row->checkid] ?? null;
            $severity = (int) $row->severity;
            if ($pack === null || !isset(self::PENALTY[$severity])) {
                continue;
            }
            $count = (int) $row->total;
            $weight = $row->status === finding::STATUS_IGNORED ? self::IGNORED_FACTOR : 1;
            $scale = in_array($pack, self::COURSE_PACKS, true) ? $factor : 1;
            $packs[$pack]->penalty += $count * $weight * self::PENALTY[$severity] * $scale;
            if ($row->status === finding::STATUS_OPEN) {
                $packs[$pack]->{$names[$severity]} += $count;
            }
        }
        $rows->close();

        $weighted = 0;
        $weights = 0;
        foreach ($packs as $pack => $result) {
            $result->score = (int) round(100 - min(100, $result->penalty));
            unset($result->penalty);
            $weighted += $result->score * self::WEIGHTS[$pack];
            $weights += self::WEIGHTS[$pack];
        }
        $overall = $weights ? (int) round($weighted / $weights) : null;
        return (object) ['overall' => $overall, 'status' => self::status($overall), 'packs' => $packs];
    }

    /**
     * Status label of an overall score.
     *
     * @param int|null $score
     * @return string|null healthy, attention, atrisk, or null without a score.
     */
    public static function status(?int $score): ?string {
        if ($score === null) {
            return null;
        }
        if ($score >= self::HEALTHY) {
            return 'healthy';
        }
        return $score >= self::ATTENTION ? 'attention' : 'atrisk';
    }

    /**
     * Store the current site score as a snapshot (weekly task).
     *
     * @param int|null $time Snapshot time, default now.
     * @return stdClass The stored score.
     */
    public static function snapshot(?int $time = null): stdClass {
        global $DB;
        $time = $time ?? time();
        $score = self::site();
        $rows = [];
        if ($score->overall !== null) {
            $rows[] = ['timecreated' => $time, 'pack' => self::OVERALL, 'score' => $score->overall,
                'critical' => 0, 'warning' => 0, 'info' => 0];
        }
        foreach ($score->packs as $pack => $result) {
            $rows[] = ['timecreated' => $time, 'pack' => $pack, 'score' => $result->score,
                'critical' => $result->critical, 'warning' => $result->warning, 'info' => $result->info];
        }
        if ($rows) {
            $DB->insert_records(self::TABLE, $rows);
        }
        return $score;
    }

    /**
     * Stored scores of a pack (or the overall score), oldest first.
     *
     * @param string $pack A pack, or OVERALL.
     * @param int $weeks How many of the latest snapshots.
     * @return stdClass[] Objects with timecreated and score.
     */
    public static function history(string $pack = self::OVERALL, int $weeks = 12): array {
        global $DB;
        $rows = $DB->get_records(self::TABLE, ['pack' => $pack], 'timecreated DESC', 'id, timecreated, score', 0, $weeks);
        return array_reverse(array_values($rows));
    }

    /**
     * Delete snapshots older than two years (cleanup task).
     *
     * @return int Rows deleted.
     */
    public static function delete_old(): int {
        global $DB;
        $before = time() - 2 * YEARSECS;
        $count = $DB->count_records_select(self::TABLE, 'timecreated < :before', ['before' => $before]);
        if ($count) {
            $DB->delete_records_select(self::TABLE, 'timecreated < :before', ['before' => $before]);
        }
        return $count;
    }
}
