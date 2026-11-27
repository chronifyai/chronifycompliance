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
use local_chronifycompliance\scorm\recommender;

/**
 * A SCORM activity completes when it is opened: "view" is its only completion condition.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormrequireview extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormrequireview';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
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
            if (!$cm->completionview || $this->has_strong_rule($cm)) {
                continue;
            }
            $inspection = $this->inspect($context, $cm);
            $evidence = $inspection['evidence'];
            $behavior = $inspection['recommendation']['behavior'] ?? null;
            $recommended = $inspection['recommendation']['settings'] ?? [];
            $nostatus = !empty($behavior['nostatus']) || (!empty($evidence['sufficient']) && !$evidence['withstatus']);
            if ($nostatus && empty($recommended['completionscorerequired'])) {
                // The package sends no status (and no score to use instead): a status rule could never be met.
                $recommended = [];
            } else if (empty($recommended['completionstatusrequired']) && empty($recommended['completionscorerequired'])) {
                // Require "passed or completed" only when something shows the package reports a status.
                $known = ($behavior['basis'] ?? 'none') !== 'none' || (int) $evidence['withstatus'] > 0;
                $recommended = $known ? ['completionstatusrequired' => recommender::EITHER] + $recommended : [];
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormrequireview:message',
                $this->cm_params($cm),
                ['scormid' => (int) $inspection['scorm']->id, 'recommended' => $recommended],
                (int) $cmid
            );
        }
        return $findings;
    }
}
