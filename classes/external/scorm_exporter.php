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

namespace local_completiondoctor\external;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_completiondoctor\check\finding;
use local_completiondoctor\check\scorm_check;

/**
 * Converts SCORM analyses to web service data: typed key fields plus the full analysis as JSON.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_exporter {
    /**
     * Export a package analysis, with optional evidence, recommendation and stage issues.
     *
     * @param array $package Package analysis.
     * @param array|null $evidence Runtime evidence.
     * @param array|null $recommendation Recommendation.
     * @param array $stageissues Stage fit issues (language references).
     * @return array
     */
    public static function export(
        array $package,
        ?array $evidence = null,
        ?array $recommendation = null,
        array $stageissues = []
    ): array {
        $render = fn(array $refs) => array_values(array_map(fn($ref) => (string) finding::render_reference($ref), $refs));
        $manifest = $package['manifest'] ?? [];
        $tool = $package['tool'] ?? [];
        $bandwidth = $package['bandwidth'] ?? [];
        $statuses = [];
        foreach ($evidence['values'] ?? [] as $value => $count) {
            $statuses[] = ['value' => (string) $value, 'count' => (int) $count];
        }
        $settings = [];
        foreach ($recommendation['settings'] ?? [] as $field => $value) {
            $settings[] = [
                'field' => $field,
                'current' => scorm_check::format_value($field, $recommendation['current'][$field]),
                'recommended' => scorm_check::format_value($field, $value),
                'changed' => array_key_exists($field, $recommendation['diff']),
            ];
        }
        return [
            'status' => (string) ($package['status'] ?? ''),
            'error' => (string) ($package['error'] ?? ''),
            'scormversion' => (string) ($manifest['version'] ?? ''),
            'edition' => (string) ($manifest['edition'] ?? ''),
            'scocount' => (int) ($manifest['scocount'] ?? 0),
            'tool' => (string) ($tool['tool'] ?? ''),
            'toolversion' => (string) ($tool['version'] ?? ''),
            'toolconfidence' => (int) ($tool['confidence'] ?? 0),
            'reportingmode' => (string) ($tool['reportingmode'] ?? ''),
            'stagewidth' => (int) ($package['stage']['width'] ?? 0),
            'stageheight' => (int) ($package['stage']['height'] ?? 0),
            'fluid' => !empty($package['stage']['fluid']),
            'totalbytes' => (int) ($bandwidth['totalbytes'] ?? 0),
            'filecount' => (int) ($bandwidth['filecount'] ?? 0),
            'startupbytes' => (int) ($bandwidth['startupbytes'] ?? 0),
            'minmbps' => (float) ($bandwidth['minmbps'] ?? 0),
            'sharedbuilds' => !empty($package['builds']['shared']),
            'builddifferences' => $render($package['builds']['differences'] ?? []),
            'attempts' => (int) ($evidence['attempts'] ?? 0),
            'statuses' => $statuses,
            'confidence' => (string) ($recommendation['confidence'] ?? ''),
            'settings' => $settings,
            'reasons' => $render($recommendation['reasons'] ?? []),
            'warnings' => $render($recommendation['warnings'] ?? []),
            'stageissues' => $render($stageissues),
            'analysisjson' => json_encode($package),
        ];
    }

    /**
     * Structure of an exported analysis.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, unsupported, error or missingfile'),
            'error' => new external_value(PARAM_ALPHANUMEXT, 'Reason when the package could not be analyzed'),
            'scormversion' => new external_value(PARAM_TEXT, 'SCORM_1.2 or SCORM_1.3'),
            'edition' => new external_value(PARAM_TEXT, 'SCORM 2004 edition'),
            'scocount' => new external_value(PARAM_INT, 'Number of SCOs'),
            'tool' => new external_value(PARAM_ALPHANUMEXT, 'Authoring tool id, or unknown'),
            'toolversion' => new external_value(PARAM_TEXT, 'Tool version'),
            'toolconfidence' => new external_value(PARAM_INT, 'Detection confidence 0-100'),
            'reportingmode' => new external_value(PARAM_TEXT, 'Reporting mode, for example passed-incomplete'),
            'stagewidth' => new external_value(PARAM_INT, 'Fixed stage width, 0 when unknown'),
            'stageheight' => new external_value(PARAM_INT, 'Fixed stage height, 0 when unknown'),
            'fluid' => new external_value(PARAM_BOOL, 'Responsive package'),
            'totalbytes' => new external_value(PARAM_INT, 'Uncompressed size'),
            'filecount' => new external_value(PARAM_INT, 'Number of files'),
            'startupbytes' => new external_value(PARAM_INT, 'Bytes loaded for the first screen'),
            'minmbps' => new external_value(PARAM_FLOAT, 'Connection speed needed, Mbps'),
            'sharedbuilds' => new external_value(PARAM_BOOL, 'Several builds share one SCO'),
            'builddifferences' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Difference')),
            'attempts' => new external_value(PARAM_INT, 'Attempts sampled'),
            'statuses' => new external_multiple_structure(new external_single_structure([
                'value' => new external_value(PARAM_TEXT, 'Status value'),
                'count' => new external_value(PARAM_INT, 'Times reported'),
            ])),
            'confidence' => new external_value(PARAM_ALPHA, 'Recommendation confidence: high, medium or low'),
            'settings' => new external_multiple_structure(new external_single_structure([
                'field' => new external_value(PARAM_ALPHANUMEXT, 'scorm table field'),
                'current' => new external_value(PARAM_TEXT, 'Current value'),
                'recommended' => new external_value(PARAM_TEXT, 'Recommended value'),
                'changed' => new external_value(PARAM_BOOL, 'Whether the recommendation changes it'),
            ])),
            'reasons' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Reason')),
            'warnings' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Warning')),
            'stageissues' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Display issue')),
            'analysisjson' => new external_value(PARAM_RAW, 'Full package analysis as JSON'),
        ]);
    }
}
