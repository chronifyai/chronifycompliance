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

/**
 * A package with several SCOs completes as soon as one of them reports the status, because
 * "all SCOs must return a status" is off.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormmultisco extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormmultisco';
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
            $inspection = $this->inspect($context, $cm);
            $scorm = $inspection['scorm'];
            if ($inspection['scocount'] < 2 || empty($scorm->completionstatusrequired) || $scorm->completionstatusallscos) {
                continue;
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormmultisco:message',
                $this->cm_params($cm) + ['count' => $inspection['scocount']],
                ['scormid' => (int) $scorm->id, 'recommended' => ['completionstatusallscos' => 1]],
                (int) $cmid
            );
        }
        return $findings;
    }
}
