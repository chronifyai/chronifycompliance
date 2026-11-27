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

namespace local_completiondoctor\output;

use local_completiondoctor\check\check_registry;
use moodle_url;
use renderer_base;

/**
 * Site dashboard header: title, scan action, health tiles, top problems, site health and view chips.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_header implements \core\output\named_templatable, \renderable {
    /** @var string Selected health group, or ''. */
    private string $view;
    /** @var string Selected check, or ''. */
    private string $checkid;

    /**
     * Constructor.
     *
     * @param string $view Selected health group, or ''.
     * @param string $checkid Selected check, or ''.
     */
    public function __construct(string $view, string $checkid) {
        $this->view = $view;
        $this->checkid = $checkid;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_completiondoctor/dashboard_header';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'local_completiondoctor';
        $context = \context_system::instance();
        $summary = \local_completiondoctor\local\dashboard_data::get_summary();
        $baseurl = new moodle_url('/local/completiondoctor/index.php');
        $link = fn(array $params) => (new moodle_url($baseurl, $params))->out(false);

        $tiles = [];
        foreach (
            ['critical' => 'criticalfindings', 'warning' => 'warningfindings', 'info' => 'infofindings',
                'healthy' => null] as $group => $findingskey
        ) {
            $tiles[] = [
                'group' => $group,
                'label' => get_string('dashboard:tile:' . $group, $component),
                'value' => $summary[$group],
                'detail' => $findingskey ? get_string('dashboard:tile:' . $group . ':detail', $component, $summary[$findingskey])
                    : get_string('dashboard:tile:healthy:detail', $component, $summary['healthypercent']),
                'url' => $link(['view' => $group]),
                'active' => $this->view === $group,
            ];
        }
        $problems = array_map(fn(array $p) => $p + [
            'courseslabel' => get_string(
                $p['courses'] === 1 ? 'dashboard:courses:one' : 'dashboard:courses:count',
                $component,
                $p['courses']
            ),
            'severityname' => finding_exporter::SEVERITIES[$p['severity']] ?? 'info',
            'severitylabel' => get_string('severity:' . (finding_exporter::SEVERITIES[$p['severity']] ?? 'info'), $component),
            'url' => $link(['checkid' => $p['checkid']]),
            'active' => $this->checkid === $p['checkid'],
        ], \local_completiondoctor\local\dashboard_data::get_top_problems());

        $chips = [['label' => get_string('dashboard:chip:all', $component, $summary['audited']), 'url' => $baseurl->out(false),
            'active' => $this->view === '' && $this->checkid === '']];
        foreach (['critical', 'warning', 'info', 'completionoff', 'waiting', 'healthy'] as $group) {
            // Groups that only some sites have appear when they hold courses.
            if (in_array($group, ['completionoff', 'waiting'], true) && !$summary[$group] && $this->view !== $group) {
                continue;
            }
            $chips[] = ['label' => get_string('dashboard:chip:' . $group, $component, $summary[$group]),
                'url' => $link(['view' => $group]), 'active' => $this->view === $group];
        }
        $checkname = null;
        if ($this->checkid !== '') {
            $check = check_registry::get_check($this->checkid);
            $checkname = $check ? $check->get_name() : $this->checkid;
        }

        return [
            'status' => get_string('dashboard:status', $component, ['audited' => $summary['audited'],
                'courses' => $summary['courses']]),
            'canscan' => has_capability('local/completiondoctor:runscan', $context),
            'cancancel' => has_capability('local/completiondoctor:runscan', $context)
                && \local_completiondoctor\local\scan_manager::get_active_scan() !== null,
            'scanurl' => $baseurl->out(false),
            'sesskey' => sesskey(),
            'tiles' => $tiles,
            'problems' => $problems,
            'hasproblems' => (bool) $problems,
            'sitehealth' => array_map(
                fn($line) => $line + ['neutral' => $line['ok'] === null],
                \local_completiondoctor\local\dashboard_data::get_site_health()
            ),
            'chips' => $chips,
            'checkname' => $checkname,
            'clearurl' => $baseurl->out(false),
        ];
    }
}
