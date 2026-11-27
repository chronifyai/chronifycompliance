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

namespace local_chronifycompliance\output;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\score\health_score;
use moodle_url;
use renderer_base;

/**
 * Main dashboard: overall score, pack tiles, 12 week trend and the top 3 actions, with the Not set up,
 * Scanning and Paused states. Reads only the plugin's own tables.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class main_dashboard implements \core\output\named_templatable, \renderable {
    /** @var int Actions shown. */
    public const ACTIONS = 3;
    /** @var int Checks listed in the score breakdown. */
    public const BREAKDOWN = 10;
    /** @var string[] Severity number => name. */
    private const SEVERITIES = [
        base_check::SEVERITY_CRITICAL => 'critical',
        base_check::SEVERITY_WARNING => 'warning',
        base_check::SEVERITY_INFO => 'info',
    ];

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/main_dashboard';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;
        $component = 'local_chronifycompliance';
        $context = \context_system::instance();
        $canscan = has_capability('local/chronifycompliance:runscan', $context);
        $active = scan_manager::get_active_scan();
        $lastaudit = (int) $DB->get_field_sql('SELECT MAX(timeaudited) FROM {local_chronifycompliance_audit}');
        $setup = !$lastaudit && !$active;

        $data = [
            'nav' => $output->render(new workspace_nav('dashboard')),
            'dataage' => $output->render(new data_age(null, $lastaudit ?: null)),
            'notsetup' => $setup,
            'scanning' => $active !== null,
            'paused' => scan_manager::is_paused(),
            'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'local_chronifycompliance']))->out(false),
            'canscan' => $canscan,
            'cancancel' => $canscan && $active !== null,
            'scanurl' => (new moodle_url('/local/chronifycompliance/index.php'))->out(false),
            'sesskey' => sesskey(),
            'invitation' => null,
            'setupdone' => \local_chronifycompliance\local\setup::is_done(),
            'setupurl' => (new moodle_url('/local/chronifycompliance/setup.php'))->out(false),
        ];
        if ($setup) {
            return $data;
        }

        $score = health_score::site();
        $points = health_score::site_breakdown();
        $data['score'] = $this->export_score($score);
        $data['packs'] = $this->export_packs($score);
        $data['trend'] = $this->export_trend($output);
        $data['breakdown'] = $this->export_breakdown($points);
        $data['hasbreakdown'] = (bool) $data['breakdown'];
        $data['actions'] = $this->export_actions($points);
        $data['hasactions'] = (bool) $data['actions'];
        $invite = invitation::for_page('dashboard', new moodle_url('https://chronifyai.com'));
        $data['invitation'] = $invite ? $output->render($invite) : null;
        return $data;
    }

    /**
     * Overall score card.
     *
     * @param \stdClass $score See health_score::site().
     * @return array
     */
    private function export_score(\stdClass $score): array {
        $component = 'local_chronifycompliance';
        $change = null;
        $lastweek = health_score::last_week();
        if ($score->overall !== null && $lastweek !== null) {
            $diff = $score->overall - $lastweek;
            $change = $diff > 0 ? get_string('dashboard:score:up', $component, $diff)
                : ($diff < 0 ? get_string('dashboard:score:down', $component, -$diff)
                : get_string('dashboard:score:same', $component));
        }
        return [
            'value' => $score->overall,
            'hasvalue' => $score->overall !== null,
            'status' => $score->status,
            'statuslabel' => $score->status ? get_string('score:' . $score->status, $component) : null,
            'change' => $change,
        ];
    }

    /**
     * One tile per pack, in display order. Packs without enabled checks show that they have none yet.
     *
     * @param \stdClass $score
     * @return array
     */
    private function export_packs(\stdClass $score): array {
        $component = 'local_chronifycompliance';
        $pages = workspace_nav::get_pages();
        $workspace = [
            base_check::PACK_SECURITY => 'security',
            base_check::PACK_COMPLETION => 'completion',
            base_check::PACK_SITE => 'completion',
            base_check::PACK_SCORM => 'completion',
        ];
        $tiles = [];
        foreach (base_check::get_packs() as $pack) {
            $result = $score->packs[$pack] ?? null;
            $tile = [
                'pack' => $pack,
                'name' => base_check::get_pack_name($pack),
                'hasscore' => $result !== null,
                'score' => $result->score ?? null,
                'url' => isset($pages[$workspace[$pack]]) ? $pages[$workspace[$pack]]->out(false) : null,
                'severity' => 'none',
            ];
            if ($result === null) {
                $tile['note'] = get_string('dashboard:pack:nochecks', $component);
            } else if ($result->critical) {
                $tile['note'] = get_string('dashboard:pack:critical', $component, $result->critical);
                $tile['severity'] = 'critical';
            } else if ($result->warning) {
                $tile['note'] = get_string(
                    $result->warning === 1 ? 'dashboard:pack:warning' : 'dashboard:pack:warnings',
                    $component,
                    $result->warning
                );
                $tile['severity'] = 'warning';
            } else {
                $tile['note'] = get_string('dashboard:pack:nocritical', $component);
                $tile['severity'] = 'good';
            }
            $tiles[] = $tile;
        }
        return $tiles;
    }

    /**
     * The last 12 weekly scores as a line chart, or a note when there are fewer than two.
     *
     * @param renderer_base $output
     * @return array
     */
    private function export_trend(renderer_base $output): array {
        $history = health_score::history(health_score::OVERALL, 12);
        if (count($history) < 2) {
            return ['haschart' => false, 'chart' => null];
        }
        $chart = new \core\chart_line();
        $chart->set_legend_options(['display' => false]);
        $series = new \core\chart_series(
            get_string('dashboard:trend', 'local_chronifycompliance'),
            array_map(fn($row) => (int) $row->score, $history)
        );
        $chart->add_series($series);
        $chart->set_labels(array_map(
            fn($row) => userdate($row->timecreated, get_string('strftimedateshort', 'langconfig')),
            $history
        ));
        $axis = $chart->get_yaxis(0, true);
        $axis->set_min(0);
        $axis->set_max(100);
        return ['haschart' => true, 'chart' => $output->render_chart($chart, false)];
    }

    /**
     * Checks that cost the most points.
     *
     * @param array $points Check id => points.
     * @return array
     */
    private function export_breakdown(array $points): array {
        $rows = [];
        foreach (array_slice($points, 0, self::BREAKDOWN, true) as $checkid => $lost) {
            $check = check_registry::get_check($checkid);
            if (!$check || $lost <= 0) {
                continue;
            }
            $rows[] = [
                'name' => $check->get_name(),
                'pack' => base_check::get_pack_name($check->get_pack()),
                'points' => self::format_points($lost),
            ];
        }
        return $rows;
    }

    /**
     * Points as shown: whole points, or "less than 1".
     *
     * @param float $points
     * @return string
     */
    public static function format_points(float $points): string {
        return $points < 0.5 ? get_string('dashboard:points:less', 'local_chronifycompliance')
            : get_string('dashboard:points', 'local_chronifycompliance', (int) round($points));
    }

    /**
     * The open problems that would raise the score most, each with one main action.
     *
     * @param array $points Check id => points, largest first.
     * @return array
     */
    private function export_actions(array $points): array {
        global $DB;
        $component = 'local_chronifycompliance';
        if (!$points) {
            return [];
        }
        $stats = $DB->get_records_sql(
            "SELECT checkid, MAX(severity) AS severity, COUNT(1) AS total, COUNT(DISTINCT courseid) AS courses,
                    SUM(fixable) AS fixable, MIN(id) AS firstid, MIN(courseid) AS courseid
               FROM {local_chronifycompliance_finding}
              WHERE status = :open
           GROUP BY checkid",
            ['open' => finding::STATUS_OPEN]
        );
        $pages = workspace_nav::get_pages();
        $actions = [];
        foreach ($points as $checkid => $lost) {
            $check = check_registry::get_check($checkid);
            $stat = $stats[$checkid] ?? null;
            if (!$check || !$stat) {
                continue;
            }
            $severity = self::SEVERITIES[(int) $stat->severity] ?? 'info';
            $action = [
                'checkid' => $checkid,
                'severity' => $severity,
                'severitylabel' => get_string('severity:' . $severity, $component),
                'points' => self::format_points($lost),
                'description' => $check->get_description(),
                'fixid' => null,
                'url' => null,
                'linktext' => null,
            ];
            $single = (int) $stat->total === 1;
            if ($check->get_scope() === base_check::SCOPE_SITE || $single) {
                $record = $DB->get_record('local_chronifycompliance_finding', ['id' => $stat->firstid]);
                $action['text'] = finding::from_record($record)->get_message();
            } else {
                $action['text'] = get_string('dashboard:action:courses', $component, [
                    'name' => $check->get_name(), 'courses' => (int) $stat->courses]);
            }
            if ($single && (int) $stat->fixable) {
                $action['fixid'] = (int) $stat->firstid;
            } else if ($check->get_scope() !== base_check::SCOPE_SITE && (int) $stat->courses === 1) {
                $action['url'] = (new moodle_url('/local/chronifycompliance/course.php', ['id' => $stat->courseid]))->out(false);
                $action['linktext'] = get_string('dashboard:action:opencourse', $component);
            } else if ($check->get_scope() !== base_check::SCOPE_SITE && isset($pages['completion'])) {
                $action['url'] = (new moodle_url($pages['completion'], ['checkid' => $checkid]))->out(false);
                $action['linktext'] = get_string('dashboard:action:review', $component);
            }
            $action['hasmain'] = $action['fixid'] || $action['url'];
            $actions[] = $action;
            if (count($actions) === self::ACTIONS) {
                break;
            }
        }
        return $actions;
    }
}
