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
 * The package was replaced while learners had saved progress from the earlier version.
 *
 * Authoring tools store the resume position by internal ids (Rise lesson ids, Storyline slide
 * positions). After a republish those ids can be gone, so learners who resume see a blank screen,
 * endless loading or "page not found", including in review mode after completion.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormreplaced extends scorm_check {
    /** @var int forcenewattempt: when the previous attempt is completed, passed or failed. */
    private const FORCEATTEMPT_ONCOMPLETE = 1;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormreplaced';
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
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $findings = [];
        foreach ($this->get_scorm_cms($context, false) as $cmid => $cm) {
            $inspection = $this->inspect($context, $cm);
            $stale = (int) ($inspection['evidence']['stale'] ?? 0);
            if (!$inspection['replaced'] || !$stale) {
                continue;
            }
            $details = ['scormid' => (int) $inspection['scorm']->id, 'stale' => $stale,
                'packagetime' => $inspection['packagetime']];
            if ((int) $inspection['scorm']->forcenewattempt === 0) {
                $details['recommended'] = ['forcenewattempt' => self::FORCEATTEMPT_ONCOMPLETE];
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormreplaced:message',
                $this->cm_params($cm) + ['count' => $stale, 'date' => ['date' => (int) $inspection['packagetime']]],
                $details,
                (int) $cmid
            );
        }
        return $findings;
    }
}
