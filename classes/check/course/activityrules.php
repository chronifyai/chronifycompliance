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
 * Completion rules that the activity's own settings make impossible or fragile:
 * - an assignment requires a submission but has no submission types enabled;
 * - an H5P activity requires a grade but does not record attempts (tracking off);
 * - a quiz requires a passing grade with limited attempts and no "or all attempts used" option.
 * Critical for required activities, warning for the others (a quiz with limited attempts: always a warning).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activityrules extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'activityrules';
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
        global $DB;
        $courseid = (int) $context->course->id;
        $required = $this->get_required_cms($context);
        $bytype = [];
        foreach ($this->get_tracked_cms($context) as $cm) {
            if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
                $bytype[$cm->modname][$cm->instance] = $cm;
            }
        }
        // One query per activity type present, for the settings that matter.
        $fields = ['assign' => 'id, nosubmissions', 'h5pactivity' => 'id, enabletracking',
            'quiz' => 'id, attempts, completionattemptsexhausted'];
        $findings = [];
        foreach ($fields as $modname => $select) {
            if (empty($bytype[$modname])) {
                continue;
            }
            $instances = $DB->get_records_list($modname, 'id', array_keys($bytype[$modname]), '', $select);
            foreach ($bytype[$modname] as $instanceid => $cm) {
                $instance = $instances[$instanceid] ?? null;
                $key = $instance ? $this->get_problem($cm, $instance) : null;
                if ($key === null) {
                    continue;
                }
                $severity = $key === 'quizattempts' || !isset($required[$cm->id]) ? self::SEVERITY_WARNING : null;
                $findings[] = $this->make_finding(
                    $courseid,
                    'check:activityrules:message' . $key,
                    $this->cm_params($cm) + ($key === 'quizattempts' ? ['attempts' => (int) $instance->attempts] : []),
                    [],
                    (int) $cm->id,
                    ['case' => $key],
                    $severity
                );
            }
        }
        return $findings;
    }

    /**
     * Problem of one activity.
     *
     * @param \cm_info $cm
     * @param \stdClass $instance Activity record with the fields of run().
     * @return string|null Message suffix, or null when fine.
     */
    private function get_problem(\cm_info $cm, \stdClass $instance): ?string {
        $rules = (array) ($cm->customdata['customcompletionrules'] ?? []);
        $usesgrade = $cm->completiongradeitemnumber !== null || !empty($cm->completionpassgrade);
        return match ($cm->modname) {
            'assign' => !empty($rules['completionsubmit']) && !empty($instance->nosubmissions) ? 'assignnosubmit' : null,
            'h5pactivity' => $usesgrade && empty($instance->enabletracking) ? 'h5pnotracking' : null,
            'quiz' => !empty($cm->completionpassgrade) && (int) $instance->attempts > 0
                && empty($instance->completionattemptsexhausted) ? 'quizattempts' : null,
            default => null,
        };
    }
}
