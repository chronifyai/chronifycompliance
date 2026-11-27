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

namespace local_completiondoctor\scorm;

/**
 * What a SCORM activity actually reported: counts of the status values, score presence, saved
 * progress sizes and stale attempts, over the latest attempts. Aggregates only, no per-learner data.
 *
 * Cost (sized for production scale): one indexed query for the latest N attempt ids
 * (scorm_attempt.scormid), then ONE pass over the values of those attempts (index on
 * scorm_scoes_value.attemptid), reading the text value only for the few elements that matter.
 * Results are cached per activity for a day (application cache "evidence").
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_evidence {
    /** @var int Default number of attempts sampled. */
    public const DEFAULT_SAMPLE = 200;
    /** @var int Attempts needed before evidence is trusted. */
    public const MIN_ATTEMPTS = 5;
    /** @var string[] Status elements. */
    public const STATUS_ELEMENTS = ['cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status'];
    /** @var string[] Score elements. */
    public const SCORE_ELEMENTS = ['cmi.core.score.raw', 'cmi.score.raw'];
    /** @var string[] Elements holding the highest possible score. */
    public const SCOREMAX_ELEMENTS = ['cmi.core.score.max', 'cmi.score.max'];
    /** @var string[] Elements holding the resume position. */
    public const RESUME_ELEMENTS = ['cmi.suspend_data', 'cmi.core.lesson_location', 'cmi.location'];
    /** @var int SCORM 1.2 limit of cmi.suspend_data (CMIString4096). */
    public const SUSPEND_LIMIT_12 = 4096;
    /** @var int Size from which suspend data is considered close to the SCORM 1.2 limit. */
    public const SUSPEND_NEAR_12 = 3600;

    /**
     * Evidence of a SCORM instance, from the cache when available.
     *
     * @param int $scormid
     * @param int|null $packagetime Time the current package file was stored (to count stale attempts).
     * @param bool $refresh Ignore the cache.
     * @return array See collect().
     */
    public static function get(int $scormid, ?int $packagetime = null, bool $refresh = false): array {
        $cache = \cache::make('local_completiondoctor', 'evidence');
        $key = $scormid . '_' . (int) $packagetime;
        if (!$refresh) {
            $cached = $cache->get($key);
            if ($cached !== false) {
                return $cached;
            }
        }
        $evidence = self::collect($scormid, null, $packagetime);
        $cache->set($key, $evidence);
        return $evidence;
    }

    /**
     * Collect the evidence of a SCORM instance.
     *
     * @param int $scormid
     * @param int|null $sample Number of latest attempts, default from the settings.
     * @param int|null $packagetime Time the current package file was stored, to count stale attempts.
     * @return array Keys: attempts, statuses (element => value => count), values (value => count, all
     *               status elements together), withstatus, nostatus, withscore, maxraw (highest raw score),
     *               scoremax (count of each score.max value reported), sufficient, suspend (with
     *               withdata, maxlength, over and near counts), stale (attempts with resume data last
     *               saved before $packagetime).
     */
    public static function collect(int $scormid, ?int $sample = null, ?int $packagetime = null): array {
        global $DB;
        $sample = $sample ?? ((int) get_config('local_completiondoctor', 'evidencesample') ?: self::DEFAULT_SAMPLE);
        $evidence = ['attempts' => 0, 'statuses' => [], 'values' => [], 'withstatus' => 0, 'nostatus' => 0,
            'withscore' => 0, 'maxraw' => null, 'scoremax' => [], 'sufficient' => false,
            'suspend' => ['withdata' => 0, 'maxlength' => 0, 'over' => 0, 'near' => 0], 'stale' => 0];

        $attemptids = array_keys($DB->get_records_sql(
            'SELECT id FROM {scorm_attempt} WHERE scormid = :scormid ORDER BY id DESC',
            ['scormid' => $scormid],
            0,
            $sample
        ));
        if (!$attemptids) {
            return $evidence;
        }
        $evidence['attempts'] = count($attemptids);
        $evidence['sufficient'] = $evidence['attempts'] >= self::MIN_ATTEMPTS;

        $names = array_merge(self::STATUS_ELEMENTS, self::SCORE_ELEMENTS, self::SCOREMAX_ELEMENTS, self::RESUME_ELEMENTS);
        [$elsql, $elparams] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED, 'el');
        $elements = $DB->get_records_select_menu('scorm_element', "element {$elsql}", $elparams, '', 'id, element');
        $kind = [];
        foreach ($elements as $id => $name) {
            $kind[$id] = in_array($name, self::STATUS_ELEMENTS, true) ? 'status'
                : (in_array($name, self::SCORE_ELEMENTS, true) ? 'score'
                    : (in_array($name, self::SCOREMAX_ELEMENTS, true) ? 'scoremax'
                        : ($name === 'cmi.suspend_data' ? 'suspend' : 'resume')));
        }

        // One pass over the value rows of the few elements used, the text only for status and score
        // elements and the length only for suspend data. Interactions and other elements are not read.
        if (!$elements) {
            $evidence['nostatus'] = $evidence['attempts'];
            return $evidence;
        }
        [$idsql, $idparams] = $DB->get_in_or_equal(array_keys($elements), SQL_PARAMS_NAMED, 'eid');
        $statusids = array_keys(array_filter($kind, fn($k) => in_array($k, ['status', 'score', 'scoremax'], true)));
        $suspendids = array_keys(array_filter($kind, fn($k) => $k === 'suspend'));
        $short = $statusids ? self::case_sql($statusids, $DB->sql_substr('v.value', 1, 32), 'st') : ['NULL', []];
        $length = $suspendids ? self::case_sql($suspendids, $DB->sql_length('v.value'), 'sp') : ['NULL', []];
        [$attsql, $attparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $rows = $DB->get_recordset_sql(
            "SELECT v.id, v.attemptid, v.elementid, {$short[0]} AS shortvalue, {$length[0]} AS valuelength
               FROM {scorm_scoes_value} v
              WHERE v.attemptid {$attsql} AND v.elementid {$idsql}",
            $short[1] + $length[1] + $attparams + $idparams
        );

        $withstatus = [];
        $withscore = [];
        $hasresume = [];
        foreach ($rows as $row) {
            $attempt = (int) $row->attemptid;
            $type = $kind[$row->elementid] ?? null;
            if ($type === 'status') {
                $status = strtolower(trim((string) $row->shortvalue));
                $element = $elements[$row->elementid];
                $evidence['statuses'][$element][$status] = ($evidence['statuses'][$element][$status] ?? 0) + 1;
                $evidence['values'][$status] = ($evidence['values'][$status] ?? 0) + 1;
                $withstatus[$attempt] = true;
            } else if ($type === 'score') {
                $withscore[$attempt] = true;
                $raw = trim((string) $row->shortvalue);
                if (is_numeric($raw)) {
                    $evidence['maxraw'] = max($evidence['maxraw'] ?? (float) $raw, (float) $raw);
                }
            } else if ($type === 'scoremax') {
                $max = trim((string) $row->shortvalue);
                if (is_numeric($max)) {
                    $key = (string) (float) $max;
                    $evidence['scoremax'][$key] = ($evidence['scoremax'][$key] ?? 0) + 1;
                }
            } else if ($type === 'suspend' || $type === 'resume') {
                $hasresume[$attempt] = true;
                if ($type === 'suspend') {
                    $size = (int) $row->valuelength;
                    $evidence['suspend']['withdata']++;
                    $evidence['suspend']['maxlength'] = max($evidence['suspend']['maxlength'], $size);
                    $evidence['suspend']['over'] += (int) ($size > self::SUSPEND_LIMIT_12);
                    $evidence['suspend']['near'] += (int) ($size > self::SUSPEND_NEAR_12);
                }
            }
        }
        $rows->close();

        $evidence['withstatus'] = count($withstatus);
        $evidence['withscore'] = count($withscore);
        $evidence['nostatus'] = $evidence['attempts'] - $evidence['withstatus'];
        if ($packagetime && $hasresume) {
            // Last write of each resumable attempt, in one grouped query.
            [$ressql, $resparams] = $DB->get_in_or_equal(array_keys($hasresume), SQL_PARAMS_NAMED, 'res');
            $lastwrite = $DB->get_records_sql_menu(
                "SELECT attemptid, MAX(timemodified)
                   FROM {scorm_scoes_value}
                  WHERE attemptid {$ressql}
               GROUP BY attemptid",
                $resparams
            );
            foreach (array_keys($hasresume) as $attempt) {
                $evidence['stale'] += (int) ($lastwrite[$attempt] < $packagetime);
            }
        }
        return $evidence;
    }

    /**
     * "CASE WHEN v.elementid IN (...) THEN expression END", so the text value is only read for some elements.
     *
     * @param int[] $elementids
     * @param string $expression
     * @param string $prefix Parameter prefix.
     * @return array [sql, params]
     */
    private static function case_sql(array $elementids, string $expression, string $prefix): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($elementids, SQL_PARAMS_NAMED, $prefix);
        return ["CASE WHEN v.elementid {$insql} THEN {$expression} ELSE NULL END", $params];
    }

    /**
     * Whether the package was seen sending a status value.
     *
     * @param array $evidence
     * @param string $status passed, completed, failed, incomplete...
     * @return bool
     */
    public static function has_status(array $evidence, string $status): bool {
        return !empty($evidence['values'][$status]);
    }

    /**
     * Highest score learners can get, as the package reports it (cmi.core.score.max): the value most
     * attempts report, so scores and pass marks can be compared in the same units.
     *
     * @param array $evidence
     * @return float|null Null when no attempt reported a maximum.
     */
    public static function get_score_max(array $evidence): ?float {
        $maxes = $evidence['scoremax'] ?? [];
        if (!$maxes) {
            return null;
        }
        arsort($maxes);
        $max = (float) array_key_first($maxes);
        return $max > 0 ? $max : null;
    }
}
