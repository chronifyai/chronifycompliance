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

/**
 * Several course builds (usually one per language) share one SCO, and so one resume record.
 *
 * Critical when the builds differ in structure: a learner who switches language resumes from data
 * written by the other build, which causes endless loading or a blank screen. Info when they match:
 * it works, but only while every build is republished from the same source.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormmultibuild extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormmultibuild';
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
     * Applies to every course with SCORM activities.
     *
     * @param \stdClass $course
     * @return bool
     */
    public function applies_to_course(\stdClass $course): bool {
        return true;
    }

    /**
     * Not fixable in Moodle: the package must be rebuilt.
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
        foreach ($this->get_scorm_cms($context, false) as $cmid => $cm) {
            $package = $this->inspect($context, $cm)['package'];
            $builds = $package['builds'] ?? null;
            if (($package['status'] ?? '') !== package_analyzer::STATUS_OK || empty($builds['shared'])) {
                continue;
            }
            $languages = array_map(fn($b) => $b['language'] ?? trim($b['path'], '/'), $builds['builds']);
            $params = $this->cm_params($cm) + ['count' => count($builds['builds']), 'languages' => implode(', ', $languages)];
            $details = ['builds' => $builds['builds'], 'differences' => $builds['differences']];
            if ($builds['differences']) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormmultibuild:message',
                    $params + ['differences' => $builds['differences']],
                    $details,
                    (int) $cmid
                );
            } else {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormmultibuild:messagesame',
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
