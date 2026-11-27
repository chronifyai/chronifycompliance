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

use local_completiondoctor\check\add_criterion_fix;
use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\check_registry;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;

/**
 * A key activity tracks completion but is not part of the course completion criteria, while other
 * activities are.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trackednotrequired extends course_check {
    use add_criterion_fix;

    /** @var string[] Activity types that are usually meant to count towards course completion. */
    public const KEY_MODULES = ['assign', 'h5pactivity', 'hvp', 'lesson', 'quiz', 'scorm'];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'trackednotrequired';
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
        $required = $this->get_required_cms($context);
        if (!$required || $this->get_activity_aggregation($context) === COMPLETION_AGGREGATION_ANY) {
            return [];
        }
        $candidates = array_filter($this->get_tracked_cms($context), fn($cm) => !isset($required[$cm->id])
            && $cm->visible && in_array($cm->modname, self::KEY_MODULES, true));
        if (!$candidates) {
            return [];
        }
        // Activities added after the criteria were saved are reported by addedaftercriteria, when it can tell.
        $savedtime = null;
        $addedcheck = check_registry::get_check('addedaftercriteria');
        if ($addedcheck && $addedcheck->is_enabled()) {
            $savedtime = $this->get_criteria_saved_time($context);
        }

        $findings = [];
        foreach ($candidates as $cmid => $cm) {
            if ($savedtime !== null && (int) $cm->added > $savedtime) {
                continue;
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:trackednotrequired:message',
                $this->cm_params($cm),
                [],
                (int) $cmid
            );
        }
        return $findings;
    }
}
