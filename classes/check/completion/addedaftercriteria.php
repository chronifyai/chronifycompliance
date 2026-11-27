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

namespace local_chronifycompliance\check\completion;

use local_chronifycompliance\check\add_criterion_fix;
use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_check;
use local_chronifycompliance\check\finding;

/**
 * A key activity (see trackednotrequired::KEY_MODULES) or one with a grade or submission rule was added
 * after the course completion criteria were last saved, and is not required. The save time is the one
 * recorded by the plugin; courses whose criteria were not saved since the plugin was installed are skipped.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addedaftercriteria extends course_check {
    use add_criterion_fix;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'addedaftercriteria';
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
        $candidates = array_filter(
            $this->get_tracked_cms($context),
            fn($cm) => !isset($required[$cm->id]) && $cm->visible
                && (in_array($cm->modname, trackednotrequired::KEY_MODULES, true) || $this->has_strong_rule($cm))
        );
        if (!$candidates) {
            return [];
        }
        $savedtime = $this->get_criteria_saved_time($context);
        if ($savedtime === null) {
            return [];
        }
        $findings = [];
        foreach ($candidates as $cmid => $cm) {
            if ((int) $cm->added <= $savedtime) {
                continue;
            }
            $params = $this->cm_params($cm) + ['added' => ['date' => (int) $cm->added], 'saved' => ['date' => $savedtime]];
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:addedaftercriteria:message',
                $params,
                ['added' => (int) $cm->added, 'saved' => $savedtime],
                (int) $cmid
            );
        }
        return $findings;
    }
}
