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

namespace local_chronifycompliance\check;

use cm_info;
use stdClass;

/**
 * Base class of the course completion checks, with shared helpers.
 *
 * By default a course check only applies when completion is enabled on the site and in the course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class course_check extends base_check {
    /**
     * Course scope.
     *
     * @return string
     */
    public function get_scope(): string {
        return self::SCOPE_COURSE;
    }

    /**
     * Course completion checks belong to the Completion pack.
     *
     * @return string
     */
    public function get_pack(): string {
        return self::PACK_COMPLETION;
    }

    /**
     * Only courses with completion enabled (and completion enabled on the site).
     *
     * @param stdClass $course
     * @return bool
     */
    public function applies_to_course(stdClass $course): bool {
        global $CFG;
        return !empty($CFG->enablecompletion) && !empty($course->enablecompletion);
    }

    /**
     * Activity criteria keyed by course module id.
     *
     * @param audit_context $context
     * @return stdClass[]
     */
    protected function get_required_cms(audit_context $context): array {
        return $context->remember('requiredcms', function (audit_context $context): array {
            $required = [];
            foreach ($context->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY) as $criterion) {
                $required[(int) $criterion->moduleinstance] = $criterion;
            }
            return $required;
        });
    }

    /**
     * Course modules with completion tracking that are not being deleted.
     *
     * @param audit_context $context
     * @return cm_info[] Keyed by cm id, in course order.
     */
    protected function get_tracked_cms(audit_context $context): array {
        return $context->remember('trackedcms', function (audit_context $context): array {
            $cms = [];
            foreach ($context->get_modinfo()->get_cms() as $cm) {
                if ($cm->completion != COMPLETION_TRACKING_NONE && !$cm->deletioninprogress) {
                    $cms[$cm->id] = $cm;
                }
            }
            return $cms;
        });
    }

    /**
     * Aggregation method of the activity criteria (COMPLETION_AGGREGATION_ALL when not set).
     *
     * @param audit_context $context
     * @return int
     */
    protected function get_activity_aggregation(audit_context $context): int {
        return $context->remember('activityaggregation', function (audit_context $context): int {
            global $DB;
            $method = $DB->get_field(
                'course_completion_aggr_methd',
                'method',
                ['course' => $context->course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]
            );
            return (int) $method === COMPLETION_AGGREGATION_ANY ? COMPLETION_AGGREGATION_ANY : COMPLETION_AGGREGATION_ALL;
        });
    }

    /**
     * Time the completion criteria were last saved, as recorded by the plugin's observer since the
     * plugin was installed. The log is not read: on big sites it is too slow, and its newest rows
     * rarely reach back far enough.
     *
     * @param audit_context $context
     * @return int|null Null when unknown.
     */
    protected function get_criteria_saved_time(audit_context $context): ?int {
        return $context->remember('criteriasavedtime', function (audit_context $context): ?int {
            return \local_chronifycompliance\local\audit_store::get_criteria_saved((int) $context->course->id);
        });
    }

    /**
     * Whether a course module has an automatic rule beyond "view".
     *
     * @param cm_info $cm
     * @return bool
     */
    protected function has_strong_rule(cm_info $cm): bool {
        if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
            return false;
        }
        if ($cm->completiongradeitemnumber !== null || !empty($cm->completionpassgrade)) {
            return true;
        }
        $rules = $cm->customdata['customcompletionrules'] ?? [];
        return (bool) array_filter((array) $rules);
    }

    /**
     * Grade item used by the grade completion rule of a course module.
     *
     * @param audit_context $context
     * @param cm_info $cm
     * @return \grade_item|null
     */
    protected function get_completion_grade_item(audit_context $context, cm_info $cm): ?\grade_item {
        $itemnumber = $cm->completiongradeitemnumber === null ? 0 : (int) $cm->completiongradeitemnumber;
        return $context->get_activity_grade_item($cm->modname, (int) $cm->instance, $itemnumber);
    }

    /**
     * Message parameters describing a course module.
     *
     * @param cm_info $cm
     * @return array
     */
    protected function cm_params(cm_info $cm): array {
        return ['name' => $cm->name, 'modname' => get_string('modulename', $cm->modname)];
    }
}
