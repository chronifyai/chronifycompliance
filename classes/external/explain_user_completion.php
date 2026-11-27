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

namespace local_chronifycompliance\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_chronifycompliance\learner\explainer;

/**
 * Explain why a learner has or has not completed a course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_user_completion extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id of an enrolled learner'),
        ]);
    }

    /**
     * Explain.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function execute(int $courseid, int $userid): array {
        ['courseid' => $courseid, 'userid' => $userid] = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'userid' => $userid]
        );
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/chronifycompliance:viewlearnerdiagnostic', $context);
        explainer::require_can_diagnose(get_course($courseid), $userid);

        $explanation = explainer::explain($courseid, $userid);
        \local_chronifycompliance\event\learner_diagnostic_viewed::create(['context' => $context, 'relateduserid' => $userid])
            ->trigger();
        $criteria = [];
        foreach ($explanation['criteria'] as $criterion) {
            $activity = $criterion['activity'];
            $criteria[] = [
                'id' => $criterion['id'],
                'type' => $criterion['type'],
                'title' => $criterion['typetitle'] . ': ' . $criterion['title'],
                'met' => $criterion['met'],
                'timemet' => (int) $criterion['timemet'],
                'blocking' => $criterion['blocking'],
                'cmid' => $activity ? (int) $activity['cmid'] : 0,
                'activitystate' => $activity ? (int) $activity['state'] : -1,
                'available' => $activity ? $activity['available'] : true,
                'availableinfo' => $activity ? strip_tags((string) $activity['availableinfo']) : '',
                'rules' => $activity ? array_map(fn($r) => ['rule' => $r['rule'], 'description' => strip_tags($r['description']),
                    'complete' => $r['complete'], 'failed' => $r['failed']], $activity['rules']) : [],
                'scormstatus' => $activity && $activity['scorm'] ? json_encode($activity['scorm']['values']) : '',
            ];
        }
        return [
            'completionenabled' => $explanation['enabled'],
            'enrolled' => $explanation['enrolled'],
            'completed' => $explanation['completed'],
            'timecompleted' => (int) $explanation['timecompleted'],
            'pendingaggregation' => $explanation['pendingaggregation'],
            'aggregation' => $explanation['overallaggregation'] === COMPLETION_AGGREGATION_ANY ? 'any' : 'all',
            'blocking' => $explanation['blocking'],
            'criteria' => $criteria,
        ];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'completionenabled' => new external_value(PARAM_BOOL, 'Completion tracking on for the course and site'),
            'enrolled' => new external_value(PARAM_BOOL, 'Active enrolment'),
            'completed' => new external_value(PARAM_BOOL, 'Course completed'),
            'timecompleted' => new external_value(PARAM_INT, 'Completion time, 0 when not completed'),
            'pendingaggregation' => new external_value(PARAM_BOOL, 'All conditions met but completion not recorded yet'),
            'aggregation' => new external_value(PARAM_ALPHA, 'all or any'),
            'blocking' => new external_value(PARAM_INT, 'Number of conditions stopping completion'),
            'criteria' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Criterion id'),
                'type' => new external_value(PARAM_INT, 'COMPLETION_CRITERIA_TYPE_* value'),
                'title' => new external_value(PARAM_TEXT, 'Condition'),
                'met' => new external_value(PARAM_BOOL, 'Met'),
                'timemet' => new external_value(PARAM_INT, 'When met, 0 when not met'),
                'blocking' => new external_value(PARAM_BOOL, 'Stops completion'),
                'cmid' => new external_value(PARAM_INT, 'Activity of an activity condition, 0 otherwise'),
                'activitystate' => new external_value(PARAM_INT, 'COMPLETION_* state of the activity, -1 for other conditions'),
                'available' => new external_value(PARAM_BOOL, 'Activity available to the learner'),
                'availableinfo' => new external_value(PARAM_TEXT, 'Why the activity is locked'),
                'rules' => new external_multiple_structure(new external_single_structure([
                    'rule' => new external_value(PARAM_ALPHANUMEXT, 'Completion rule'),
                    'description' => new external_value(PARAM_TEXT, 'Rule'),
                    'complete' => new external_value(PARAM_BOOL, 'Rule met'),
                    'failed' => new external_value(PARAM_BOOL, 'Rule met with a failing grade'),
                ])),
                'scormstatus' => new external_value(PARAM_RAW, 'JSON of the values sent by the latest SCORM attempt'),
            ])),
        ]);
    }
}
