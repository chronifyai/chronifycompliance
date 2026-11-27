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

namespace local_completiondoctor\check\course;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;

/**
 * A required activity completes on view or on a manual tick only. SCORM activities are covered by
 * the scormrequireview check.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weakcondition extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'weakcondition';
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
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $tracked = $this->get_tracked_cms($context);
        $findings = [];
        foreach (array_keys($this->get_required_cms($context)) as $cmid) {
            if (!isset($tracked[$cmid]) || $tracked[$cmid]->modname === 'scorm') {
                continue;
            }
            $cm = $tracked[$cmid];
            if ($cm->completion == COMPLETION_TRACKING_MANUAL) {
                $key = 'check:weakcondition:messagemanual';
            } else if (!$this->has_strong_rule($cm) && !$this->has_legacy_rules($cm)) {
                $key = $cm->completionview ? 'check:weakcondition:messageview' : 'check:weakcondition:messagenorule';
            } else {
                continue;
            }
            $findings[] = $this->make_finding((int) $context->course->id, $key, $this->cm_params($cm), [], (int) $cmid);
        }
        return $findings;
    }

    /**
     * Whether the module declares completion rules without a custom_completion class (pre-3.11 API).
     * Its rules are not visible in the course cache, so the activity is not judged.
     *
     * @param \cm_info $cm
     * @return bool
     */
    private function has_legacy_rules(\cm_info $cm): bool {
        return plugin_supports('mod', $cm->modname, FEATURE_COMPLETION_HAS_RULES)
            && !\core_completion\activity_custom_completion::get_cm_completion_class($cm->modname);
    }
}
