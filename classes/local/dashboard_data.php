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

namespace local_chronifycompliance\local;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\scorm\package_analyzer;

/**
 * Figures for the Completion workspace: course health groups, top problems, site checks and SCORM.
 *
 * Every figure comes from the plugin's own tables (one row per course in the audit table, stored findings,
 * analyzed packages); nothing here runs a check or reads Moodle's file or log tables.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_data {
    /** @var int Problems listed in "Top problems". */
    private const TOP_PROBLEMS = 6;

    /**
     * SQL conditions of the course health groups over the audit table, shared by the dashboard figures
     * and its course table. Critical, warning and info hold every course with at least one open finding of
     * that severity, so a course can be in several of them. A course without findings is in one of
     * "completion off", "waiting" (SCORM packages not analyzed yet) or healthy.
     *
     * @param string $alias Alias of the audit table.
     * @return string[] Keyed by group.
     */
    public static function group_conditions(string $alias): array {
        $nofindings = "{$alias}.critical = 0 AND {$alias}.warning = 0 AND {$alias}.info = 0";
        $off = "EXISTS (SELECT 1 FROM {course} cdoff WHERE cdoff.id = {$alias}.courseid AND cdoff.enablecompletion = 0)";
        return [
            'critical' => "{$alias}.critical > 0",
            'warning' => "{$alias}.warning > 0",
            'info' => "{$alias}.info > 0",
            'completionoff' => "{$nofindings} AND {$off}",
            'waiting' => "{$nofindings} AND NOT {$off} AND {$alias}.waiting > 0",
            'healthy' => "{$nofindings} AND NOT {$off} AND {$alias}.waiting = 0",
        ];
    }

    /**
     * Courses by health group.
     *
     * @return array Keys: courses, audited, critical, warning, info, completionoff, waiting, healthy (course
     *               counts), criticalfindings, warningfindings, infofindings and healthypercent.
     */
    public static function get_summary(): array {
        global $DB;
        $groups = self::group_conditions('a');
        $sums = [];
        foreach ($groups as $group => $condition) {
            $sums[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$group}";
        }
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS audited, " . implode(', ', $sums) . ",
                    SUM(a.critical) AS criticalfindings, SUM(a.warning) AS warningfindings, SUM(a.info) AS infofindings
               FROM {" . audit_store::TABLE . "} a
              WHERE a.courseid <> :siteid AND a.timeaudited > 0",
            ['siteid' => SITEID]
        );
        $summary = ['courses' => $DB->count_records_select('course', 'id <> :siteid', ['siteid' => SITEID])];
        foreach (array_merge(['audited', 'criticalfindings', 'warningfindings', 'infofindings'], array_keys($groups)) as $key) {
            $summary[$key] = (int) ($row->$key ?? 0);
        }
        $summary['healthypercent'] = $summary['audited'] ? (int) round($summary['healthy'] * 100 / $summary['audited']) : 0;
        return $summary;
    }

    /**
     * Checks affecting the most courses.
     *
     * @return array[] Keys checkid, name, severity, courses, percent (relative to the first).
     */
    public static function get_top_problems(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT checkid, MAX(severity) AS severity, COUNT(DISTINCT courseid) AS courses
               FROM {" . finding_store::TABLE . "}
              WHERE status = :status AND courseid <> :siteid
           GROUP BY checkid
           ORDER BY MAX(severity) DESC, COUNT(DISTINCT courseid) DESC, checkid",
            ['status' => finding::STATUS_OPEN, 'siteid' => SITEID],
            0,
            self::TOP_PROBLEMS
        );
        $max = $rows ? max(array_map(fn($r) => (int) $r->courses, $rows)) : 1;
        $problems = [];
        foreach ($rows as $row) {
            $check = check_registry::get_check($row->checkid);
            $problems[] = [
                'checkid' => $row->checkid,
                'name' => $check ? $check->get_name() : $row->checkid,
                'severity' => (int) $row->severity,
                'courses' => (int) $row->courses,
                'percent' => max(2, (int) round($row->courses * 100 / $max)),
            ];
        }
        return $problems;
    }

    /**
     * Site check lines from the stored findings of the last site audit: each enabled site check with its
     * open finding, or that it passed. Paused scans add a line.
     *
     * @return array[] Keys ok, text.
     */
    public static function get_site_health(): array {
        global $DB;
        $checks = check_registry::get_checks([base_check::SCOPE_SITE], true);
        $open = [];
        if ($checks) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($checks), SQL_PARAMS_NAMED);
            $params += ['siteid' => SITEID, 'open' => finding::STATUS_OPEN];
            $records = $DB->get_records_select(
                finding_store::TABLE,
                "courseid = :siteid AND status = :open AND checkid {$insql}",
                $params,
                'severity DESC, id'
            );
            foreach ($records as $record) {
                $open[$record->checkid] = $open[$record->checkid] ?? finding::from_record($record);
            }
        }
        $audited = $DB->record_exists_select(audit_store::TABLE, 'courseid = :siteid AND timeaudited > 0', ['siteid' => SITEID]);
        $lines = [];
        foreach ($checks as $id => $check) {
            if (isset($open[$id])) {
                $lines[] = ['ok' => false, 'text' => $open[$id]->get_message()];
            } else if ($audited) {
                $lines[] = ['ok' => true, 'text' => get_string('dashboard:siteok:' . $id, 'local_chronifycompliance')];
            }
        }
        if (scan_manager::is_paused()) {
            $lines[] = ['ok' => false, 'text' => get_string('dashboard:paused', 'local_chronifycompliance')];
        }
        return $lines;
    }

    /**
     * SCORM figures for the strip: packages analyzed, and SCORM activities with open problems.
     *
     * @return array Keys analyzed, queued, attention.
     */
    public static function get_scorm_summary(): array {
        global $DB;
        $analyzed = $DB->count_records_select(
            package_analyzer::TABLE,
            'parserversion = :v AND status = :ok',
            ['v' => package_analyzer::PARSER_VERSION, 'ok' => package_analyzer::STATUS_OK]
        );
        $attention = 0;
        $scormchecks = array_keys(check_registry::get_checks(null, true, [base_check::PACK_SCORM]));
        if ($scormchecks) {
            [$insql, $params] = $DB->get_in_or_equal($scormchecks, SQL_PARAMS_NAMED);
            $params['open'] = finding::STATUS_OPEN;
            $attention = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cmid) FROM {" . finding_store::TABLE . "}
                  WHERE status = :open AND cmid IS NOT NULL AND checkid {$insql}",
                $params
            );
        }
        return ['analyzed' => $analyzed, 'queued' => package_analyzer::count_queued(), 'attention' => $attention];
    }

    /**
     * SQL condition for courses with open problems of the SCORM and content pack.
     *
     * @param string $alias Alias of the audit table.
     * @return array [sql, params]
     */
    public static function scorm_condition(string $alias): array {
        global $DB;
        $scormchecks = array_keys(check_registry::get_checks(null, true, [base_check::PACK_SCORM])) ?: ['-'];
        [$insql, $params] = $DB->get_in_or_equal($scormchecks, SQL_PARAMS_NAMED, 'cdscorm');
        $params['cdscormopen'] = finding::STATUS_OPEN;
        return ["EXISTS (SELECT 1 FROM {" . finding_store::TABLE . "} cds
                 WHERE cds.courseid = {$alias}.courseid AND cds.status = :cdscormopen AND cds.checkid {$insql})", $params];
    }
}
