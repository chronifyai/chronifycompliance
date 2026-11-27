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

namespace local_completiondoctor\check\scorm;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\finding;
use local_completiondoctor\check\scorm_check;

/**
 * Learners' attempts show that the package never sends a completion or success status.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormnostatus extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormnostatus';
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
     * Not fixable in Moodle: the package must be republished.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return false;
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
            $evidence = $inspection['evidence'];
            if (!$evidence['sufficient'] || $evidence['withstatus'] > 0) {
                continue;
            }
            // Only a problem when completion needs a status: completion by score works without one.
            if (empty($inspection['scorm']->completionstatusrequired)) {
                continue;
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormnostatus:message',
                $this->cm_params($cm) + ['attempts' => $evidence['attempts']],
                ['attempts' => $evidence['attempts'], 'withscore' => $evidence['withscore']],
                (int) $cmid
            );
        }
        return $findings;
    }
}
