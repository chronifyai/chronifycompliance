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

use local_chronifycompliance\check\check_registry;
use moodle_url;
use renderer_base;

/**
 * Site dashboard header: title, scan action, health tiles, top problems, site health and view chips.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_header implements \core\output\named_templatable, \renderable {
    /** @var string Selected health group, or ''. */
    private string $view;
    /** @var string Selected check, or ''. */
    private string $checkid;
    /** @var bool Whether this is the part below the course table. */
    private bool $footer;

    /**
     * Constructor.
     *
     * @param string $view Selected health group, or ''.
     * @param string $checkid Selected check, or ''.
     * @param bool $footer Render the part below the course table (site checks, SCORM strip, invitation).
     */
    public function __construct(string $view, string $checkid, bool $footer = false) {
        $this->view = $view;
        $this->checkid = $checkid;
        $this->footer = $footer;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return $this->footer ? 'local_chronifycompliance/completion_footer' : 'local_chronifycompliance/dashboard_header';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'local_chronifycompliance';
        if ($this->footer) {
            return $this->export_footer($output);
        }
        $context = \context_system::instance();
        $summary = \local_chronifycompliance\local\dashboard_data::get_summary();
        $baseurl = new moodle_url('/local/chronifycompliance/completion.php');
        $link = fn(array $params) => (new moodle_url($baseurl, $params))->out(false);

        $tiles = [];
        foreach (['critical' => 'criticalfindings', 'warning' => 'warningfindings', 'healthy' => null] as $group => $findingskey) {
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
        if ($this->view === 'scorm') {
            $chips[] = ['label' => get_string('dashboard:chip:scorm', $component), 'url' => $link(['view' => 'scorm']),
                'active' => true];
        }
        $checkname = null;
        if ($this->checkid !== '') {
            $check = check_registry::get_check($this->checkid);
            $checkname = $check ? $check->get_name() : $this->checkid;
        }

        $latest = \local_chronifycompliance\local\scan_manager::get_latest_scan();
        $lastscan = null;
        if ($latest && $latest->timefinished) {
            $lastscan = get_string('completion:lastscan', $component, [
                'time' => data_age::format_time((int) $latest->timefinished), 'courses' => (int) $latest->totalitems]);
        }
        return [
            'nav' => $output->render(new workspace_nav('completion')),
            'status' => get_string('dashboard:status', $component, ['audited' => $summary['audited'],
                'courses' => $summary['courses']]),
            'lastscan' => $lastscan,
            'canscan' => has_capability('local/chronifycompliance:runscan', $context),
            'cancancel' => has_capability('local/chronifycompliance:runscan', $context)
                && \local_chronifycompliance\local\scan_manager::get_active_scan() !== null,
            'canfix' => has_capability('local/chronifycompliance:applyfix', $context),
            'scanurl' => $baseurl->out(false),
            'sesskey' => sesskey(),
            'tiles' => $tiles,
            'chips' => $chips,
            'checkname' => $checkname,
            'clearurl' => $baseurl->out(false),
        ];
    }

    /**
     * Data of the part below the course table.
     *
     * @param renderer_base $output
     * @return array
     */
    private function export_footer(renderer_base $output): array {
        $component = 'local_chronifycompliance';
        $link = fn(array $params) => (new moodle_url('/local/chronifycompliance/completion.php', $params))->out(false);
        $scorm = \local_chronifycompliance\local\dashboard_data::get_scorm_summary();
        $invite = invitation::for_page('completion', new moodle_url('https://chronifyai.com'));
        return [
            'sitehealth' => array_map(
                fn($line) => $line + ['neutral' => $line['ok'] === null],
                \local_chronifycompliance\local\dashboard_data::get_site_health()
            ),
            'scorm' => $scorm['analyzed'] || $scorm['attention'] || $scorm['queued'] ? [
                'text' => get_string('completion:scorm', $component, $scorm),
                'queued' => $scorm['queued'] ? get_string('dashboard:packagesqueued', $component, $scorm['queued']) : null,
                'url' => $scorm['attention'] ? $link(['view' => 'scorm']) : null,
            ] : null,
            'invitation' => $invite ? $output->render($invite) : null,
        ];
    }
}
