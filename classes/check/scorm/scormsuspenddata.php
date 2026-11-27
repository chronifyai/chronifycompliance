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
use local_chronifycompliance\scorm\runtime_evidence;

/**
 * SCORM 1.2 resume data (cmi.suspend_data) is over or close to the 4096 characters the standard allows.
 *
 * With "SCORM standards mode" on, Moodle refuses longer values, so progress stops being saved and
 * the learner resumes from an old position. Packages whose driver turns off its own limit
 * (USE_STRICT_SUSPEND_DATA_LIMITS = false) are at risk on such sites. SCORM 2004 allows 64000.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormsuspenddata extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormsuspenddata';
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
     * Applies to every course with SCORM activities.
     *
     * @param \stdClass $course
     * @return bool
     */
    public function applies_to_course(\stdClass $course): bool {
        return true;
    }

    /**
     * Not fixable in Moodle: republish as SCORM 2004 or reduce the tracked state.
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
        $standardsmode = (bool) get_config('scorm', 'scormstandard');
        $findings = [];
        foreach ($this->get_scorm_cms($context, false) as $cmid => $cm) {
            $inspection = $this->inspect($context, $cm);
            if ($inspection['scorm']->version !== 'SCORM_1.2') {
                continue;
            }
            $suspend = $inspection['evidence']['suspend'] ?? [];
            $params = $this->cm_params($cm) + [
                'over' => (int) ($suspend['over'] ?? 0),
                'near' => (int) ($suspend['near'] ?? 0),
                'attempts' => (int) ($inspection['evidence']['attempts'] ?? 0),
                'max' => (int) ($suspend['maxlength'] ?? 0),
                'limit' => runtime_evidence::SUSPEND_LIMIT_12,
                'mode' => get_string($standardsmode ? 'yes' : 'no'),
            ];
            $details = ['suspend' => $suspend, 'standardsmode' => $standardsmode,
                'strictsuspend' => $inspection['package']['strictsuspend'] ?? null];
            if (!empty($suspend['over'])) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormsuspenddata:messageover',
                    $params,
                    $details,
                    (int) $cmid
                );
            } else if (!empty($suspend['near'])) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormsuspenddata:messagenear',
                    $params,
                    $details,
                    (int) $cmid,
                    [],
                    self::SEVERITY_INFO
                );
            } else if ($standardsmode && ($inspection['package']['strictsuspend'] ?? null) === false) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormsuspenddata:messagestrict',
                    $params,
                    $details,
                    (int) $cmid,
                    [],
                    self::SEVERITY_INFO
                );
            }
        }
        return $findings;
    }
}
