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

namespace local_chronifycompliance\check\scorm;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\scorm_check;
use local_chronifycompliance\scorm\package_analyzer;
use local_chronifycompliance\scorm\recommender;

/**
 * The SCORM completion settings can never be met by what the package reports (for example
 * "passed" required from a package that only reports "completed"), or the package is broken
 * (no launchable SCO, manifest not at the root of the zip).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormstatusmismatch extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormstatusmismatch';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $findings = [];
        foreach ($this->get_scorm_cms($context) as $cmid => $cm) {
            $inspection = $this->inspect($context, $cm);
            $package = $inspection['package'];
            $params = $this->cm_params($cm);
            $islocal = in_array($inspection['scorm']->scormtype, ['local', 'localsync'], true);

            if ($islocal && $inspection['scocount'] === 0) {
                $key = !empty($package['manifest']['nested']) ? 'check:scormstatusmismatch:messagenested'
                    : 'check:scormstatusmismatch:messagenosco';
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    $key,
                    $params,
                    ['scormid' => (int) $inspection['scorm']->id],
                    (int) $cmid,
                    ['broken' => 1]
                );
                continue;
            }
            if (($package['status'] ?? '') !== package_analyzer::STATUS_OK || !$inspection['recommendation']) {
                continue;
            }
            $recommendation = $inspection['recommendation'];
            $mismatch = recommender::get_mismatch($recommendation['behavior'], $inspection['scorm']);
            if ($mismatch === null) {
                continue;
            }
            // Learners already marked complete means something else completes the activity (manual
            // override, another plugin, imported data): the setup is still wrong, but say so.
            $completed = $this->count_completed((int) $cmid);
            $params['note'] = $completed ? ['str' => 'check:scormstatusmismatch:note', 'a' => $completed] : '';
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormstatusmismatch:message' . $mismatch,
                $params + ['basis' => ['str' => 'basis:' . $recommendation['behavior']['basis']]],
                ['scormid' => (int) $inspection['scorm']->id, 'recommended' => $recommendation['settings'],
                    'confidence' => $recommendation['confidence']],
                (int) $cmid,
                ['mismatch' => $mismatch],
                // Learners' attempts prove it; the authoring tool settings alone only suggest it.
                $recommendation['behavior']['basis'] === 'evidence' ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING
            );
        }
        return $findings;
    }

    /**
     * Learners marked complete on the activity.
     *
     * @param int $cmid
     * @return int
     */
    private function count_completed(int $cmid): int {
        global $DB;
        return $DB->count_records_select(
            'course_modules_completion',
            'coursemoduleid = :cmid AND completionstate > 0',
            ['cmid' => $cmid]
        );
    }
}
