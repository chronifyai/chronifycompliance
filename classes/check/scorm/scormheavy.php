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
use local_completiondoctor\scorm\package_analyzer;

/**
 * The package needs a fast connection, or has so many files that loading it takes many requests.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormheavy extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormheavy';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_INFO;
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
     * Not fixable: the package must be optimized in the authoring tool.
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
        $threshold = (float) get_config('local_completiondoctor', 'heavythreshold') ?: 10.0;
        $findings = [];
        foreach ($this->get_scorm_cms($context, false) as $cmid => $cm) {
            $package = $this->inspect($context, $cm)['package'];
            if (($package['status'] ?? '') !== package_analyzer::STATUS_OK) {
                continue;
            }
            $bandwidth = $package['bandwidth'];
            $params = $this->cm_params($cm) + [
                'mbps' => format_float((float) $bandwidth['minmbps'], 1),
                'seconds' => format_float((float) $bandwidth['startupseconds3mbps'], 1),
                'size' => display_size((int) $bandwidth['totalbytes']),
                'startup' => display_size((int) $bandwidth['startupbytes']),
                'files' => $bandwidth['filecount'],
            ];
            if ((float) $bandwidth['minmbps'] > $threshold) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormheavy:message',
                    $params,
                    ['minmbps' => $bandwidth['minmbps']],
                    (int) $cmid,
                    ['type' => 'speed']
                );
            }
            if (!empty($bandwidth['manyfiles'])) {
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormheavy:messagefiles',
                    $params,
                    ['filecount' => $bandwidth['filecount']],
                    (int) $cmid,
                    ['type' => 'files']
                );
            }
        }
        return $findings;
    }
}
