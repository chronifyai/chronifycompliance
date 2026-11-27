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

use local_completiondoctor\check\finding;
use local_completiondoctor\check\scorm_check;
use local_completiondoctor\local\audit_store;
use local_completiondoctor\local\finding_store;
use local_completiondoctor\scorm\package_analyzer;
use moodle_url;
use renderer_base;

/**
 * SCORM analysis panel of one activity.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_panel implements \core\output\named_templatable, \renderable {
    /** @var \cm_info SCORM activity. */
    private \cm_info $cm;
    /** @var array See inspector::inspect(). */
    private array $inspection;

    /**
     * Constructor.
     *
     * @param \cm_info $cm
     * @param array $inspection See inspector::inspect().
     */
    public function __construct(\cm_info $cm, array $inspection) {
        $this->cm = $cm;
        $this->inspection = $inspection;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_completiondoctor/scorm_panel';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'local_completiondoctor';
        $package = $this->inspection['package'];
        $ok = ($package['status'] ?? '') === package_analyzer::STATUS_OK;
        $evidence = $this->inspection['evidence'];
        $recommendation = $this->inspection['recommendation'];
        $bandwidth = $package['bandwidth'] ?? [];

        $statuses = [];
        foreach ($evidence['values'] as $value => $count) {
            $statuses[] = ['value' => $value, 'count' => $count];
        }
        $settings = [];
        if ($recommendation) {
            foreach ($recommendation['settings'] as $field => $value) {
                $settings[] = [
                    'label' => get_string('scorm:field:' . $field, $component),
                    'current' => scorm_check::format_value($field, $recommendation['current'][$field]),
                    'recommended' => scorm_check::format_value($field, $value),
                    'changed' => array_key_exists($field, $recommendation['diff']),
                ];
            }
        }
        $resolve = fn(array $refs) => array_map(fn(array $ref) => (string) finding::render_reference($ref), $refs);

        $buildsdata = [];
        foreach ($package['builds']['builds'] ?? [] as $build) {
            $buildsdata[] = $build + ['label' => $build['language'] ?? $build['path']];
        }
        $context = \context_course::instance($this->cm->course);
        $caps = ['canfix' => has_capability('local/completiondoctor:applyfix', $context),
            'canignore' => has_capability('local/completiondoctor:ignorefinding', $context)];
        $findings = [];
        foreach (finding_store::get_course_findings((int) $this->cm->course) as $f) {
            if ((int) $f->cmid === (int) $this->cm->id) {
                $findings[] = finding_exporter::export($f, get_fast_modinfo($this->cm->course), $caps);
            }
        }

        $data = [
            'cmid' => (int) $this->cm->id,
            'name' => $this->cm->get_formatted_name(),
            'courseurl' => (new moodle_url('/local/completiondoctor/course.php', ['id' => $this->cm->course]))->out(false),
            'editurl' => (new moodle_url('/course/modedit.php', ['update' => $this->cm->id]))->out(false),
            'refreshurl' => (new moodle_url('/local/completiondoctor/scorm.php', ['cmid' => $this->cm->id]))->out(false),
            'sesskey' => sesskey(),
            'ok' => $ok,
            'pending' => ($package['status'] ?? '') === package_analyzer::STATUS_PENDING,
            'checking' => audit_store::get_requested((int) $this->cm->course) > 0,
            'error' => $ok || ($package['status'] ?? '') === package_analyzer::STATUS_PENDING ? null
                : get_string('package:error:' . ($package['error'] ?? 'missingfile'), $component),
            'version' => $package['manifest']['version'] ?? $this->inspection['scorm']->version,
            'edition' => $package['manifest']['edition'] ?? null,
            'scocount' => $package['manifest']['scocount'] ?? $this->inspection['scocount'],
            'tool' => $package['tool']['label'] ?? get_string('panel:unknowntool', $component),
            'toolversion' => isset($package['tool']['version']) ? '(' . $package['tool']['version'] . ')' : null,
            'toolconfidence' => $package['tool']['confidence'] ?? 0,
            'toolexperimental' => !empty($package['tool']['experimental']) && ($package['tool']['tool'] ?? 'unknown') !== 'unknown',
            'reportingmode' => $package['tool']['reportingmode'] ?? null,
            'masteryscore' => $package['manifest']['masteryscore'] ?? null,
            'attempts' => $evidence['attempts'],
            'statuses' => $statuses,
            'hasstatuses' => (bool) $statuses,
            'nostatus' => $evidence['nostatus'],
            'withscore' => $evidence['withscore'],
            'hasrecommendation' => (bool) $recommendation,
            'settings' => $settings,
            'confidence' => $recommendation ? get_string('panel:confidence:' . $recommendation['confidence'], $component) : null,
            'reasons' => $recommendation ? $resolve($recommendation['reasons']) : [],
            'warnings' => $recommendation ? $resolve($recommendation['warnings']) : [],
            'size' => isset($bandwidth['totalbytes']) ? display_size((int) $bandwidth['totalbytes']) : null,
            'files' => $bandwidth['filecount'] ?? null,
            'bandwidth' => $ok ? get_string('panel:bandwidthverdict', $component, [
                'mbps' => format_float((float) $bandwidth['minmbps'], 1),
                'seconds' => format_float((float) $bandwidth['startupseconds3mbps'], 1),
                'startup' => display_size((int) ($bandwidth['startupbytes'] ?? 0)),
            ]) : null,
            'stage' => !empty($package['stage']['fluid']) ? get_string('panel:fluid', $component)
                : (!empty($package['stage']['width']) ? $package['stage']['width'] . '×' . $package['stage']['height'] : null),
            'stageissues' => $resolve($this->inspection['stageissues']),
            'hasstageissues' => (bool) $this->inspection['stageissues'],
            'builds' => $buildsdata,
            'hasbuilds' => !empty($package['builds']['shared']),
            'builddifferences' => $resolve($package['builds']['differences'] ?? []),
            'findings' => $findings,
            'hasfindings' => (bool) $findings,
        ];
        return $data;
    }
}
