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

namespace local_completiondoctor\local;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\base_check;
use local_completiondoctor\check\check_registry;
use local_completiondoctor\check\finding;
use local_completiondoctor\scorm\package_analyzer;

/**
 * Figures for the dashboard header: course health groups, top problems and site health.
 *
 * Every figure is an aggregate over the plugin's own tables (one row per course in the audit table),
 * plus the live site checks, which are cheap.
 *
 * @package    local_completiondoctor
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
     * Site health lines: each enabled site check and SCORM package totals. The site check results and
     * the package count are cached for a few minutes, so dashboard views do not recompute them.
     *
     * @return array[] Keys ok, text.
     */
    public static function get_site_health(): array {
        global $DB;
        $cache = \cache::make('local_completiondoctor', 'sitehealth');
        $cached = $cache->get('lines');
        if ($cached === false) {
            $checks = [];
            $context = new audit_context(get_site(), false);
            foreach (check_registry::get_checks([base_check::SCOPE_SITE], true) as $check) {
                $findings = $check->run($context);
                $checks[] = ['ok' => !$findings, 'finding' => $findings ? $findings[0]->to_record() : null,
                    'checkid' => $check->get_id()];
            }
            $packages = (int) $DB->count_records_sql("SELECT COUNT(DISTINCT contenthash) FROM {files}
                WHERE component = 'mod_scorm' AND filearea = 'package' AND filename <> '.'");
            $cached = ['checks' => $checks, 'packages' => $packages];
            $cache->set('lines', $cached);
        }
        $lines = [];
        foreach ($cached['checks'] as $check) {
            $lines[] = [
                'ok' => $check['ok'],
                'text' => $check['finding'] ? finding::from_record((object) $check['finding'])->get_message()
                    : get_string('dashboard:siteok:' . $check['checkid'], 'local_completiondoctor'),
            ];
        }
        if (scan_manager::is_paused()) {
            $lines[] = ['ok' => false, 'text' => get_string('dashboard:paused', 'local_completiondoctor')];
        }
        $queued = package_analyzer::count_queued();
        if ($queued) {
            $lines[] = ['ok' => null, 'text' => get_string('dashboard:packagesqueued', 'local_completiondoctor', $queued)];
        }
        if ($cached['packages']) {
            $analyzed = $DB->count_records_select(
                package_analyzer::TABLE,
                'parserversion = :v AND status = :ok',
                ['v' => package_analyzer::PARSER_VERSION, 'ok' => package_analyzer::STATUS_OK]
            );
            $lines[] = ['ok' => null, 'text' => get_string(
                'dashboard:packages',
                'local_completiondoctor',
                ['total' => $cached['packages'], 'analyzed' => $analyzed]
            )];
        }
        return $lines;
    }
}
