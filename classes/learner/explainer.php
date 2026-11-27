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

namespace local_completiondoctor\learner;

/**
 * Explains the course completion of one learner: each criterion with its state, which ones block
 * completion, the completion rules of required activities, what SCORM activities received, and why
 * locked activities are locked. Read-only: it reads stored completion data and never recalculates it.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explainer {
    /** @var string[] SCORM elements shown in the diagnostic. */
    private const SCORM_ELEMENTS = ['cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status',
        'cmi.core.score.raw', 'cmi.score.raw'];
    /** @var string[] Status values from best to worst, to pick the one completion counts. */
    private const STATUS_RANK = ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted', 'unknown'];

    /**
     * Explain a learner's course completion.
     *
     * @param int $courseid
     * @param int $userid
     * @return array Keys: enabled, completed, timecompleted, overallaggregation, enrolled, pendingaggregation,
     *               criteria (list), blocking (count).
     */
    public static function explain(int $courseid, int $userid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        $result = [
            'enabled' => !empty($CFG->enablecompletion) && !empty($course->enablecompletion),
            'enrolled' => is_enrolled($context, $userid, '', true),
            'completed' => false,
            'timecompleted' => null,
            'overallaggregation' => COMPLETION_AGGREGATION_ALL,
            'pendingaggregation' => false,
            'criteria' => [],
            'blocking' => 0,
        ];
        $completion = $DB->get_record('course_completions', ['userid' => $userid, 'course' => $courseid]);
        if ($completion && $completion->timecompleted) {
            $result['completed'] = true;
            $result['timecompleted'] = (int) $completion->timecompleted;
        }

        $criteria = $DB->get_records('course_completion_criteria', ['course' => $courseid], 'criteriatype, id');
        if (!$criteria) {
            return $result;
        }
        $met = $DB->get_records_menu(
            'course_completion_crit_compl',
            ['userid' => $userid, 'course' => $courseid],
            '',
            'criteriaid, timecompleted'
        );
        $methods = $DB->get_records_menu(
            'course_completion_aggr_methd',
            ['course' => $courseid],
            '',
            'COALESCE(criteriatype, 0), method'
        );
        $result['overallaggregation'] = (int) ($methods[0] ?? COMPLETION_AGGREGATION_ALL);

        $modinfo = get_fast_modinfo($course, $userid);
        $groups = [];
        foreach ($criteria as $record) {
            $type = (int) $record->criteriatype;
            $object = \completion_criteria::factory((array) $record);
            // Core cannot title an activity criterion whose activity is gone (it reads properties of null).
            $cmid = (int) $record->moduleinstance;
            $missingcm = $type === COMPLETION_CRITERIA_TYPE_ACTIVITY && !isset($modinfo->get_cms()[$cmid]);
            $item = [
                'id' => (int) $record->id,
                'type' => $type,
                'typetitle' => $object->get_type_title(),
                'title' => $missingcm ? get_string('learner:missingactivity', 'local_completiondoctor', $cmid)
                    : self::safe_title($object),
                'met' => array_key_exists($record->id, $met) && $met[$record->id],
                'timemet' => !empty($met[$record->id]) ? (int) $met[$record->id] : null,
                'blocking' => false,
                'activity' => null,
            ];
            if ($type === COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $item['activity'] = self::explain_activity($modinfo, (int) $record->moduleinstance, $userid);
            }
            $groups[$type][] = $item;
        }

        // A group is met when all its criteria are met, or any of them with "any" aggregation.
        $groupmet = [];
        foreach ($groups as $type => $items) {
            $any = (int) ($methods[$type] ?? COMPLETION_AGGREGATION_ALL) === COMPLETION_AGGREGATION_ANY
                && in_array($type, [COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_CRITERIA_TYPE_COURSE,
                    COMPLETION_CRITERIA_TYPE_ROLE], true);
            $metcount = count(array_filter($items, fn($i) => $i['met']));
            $groupmet[$type] = $any ? $metcount > 0 : $metcount === count($items);
        }
        $overallany = $result['overallaggregation'] === COMPLETION_AGGREGATION_ANY;
        $coursemet = $overallany ? in_array(true, $groupmet, true) : !in_array(false, $groupmet, true);

        foreach ($groups as $type => $items) {
            foreach ($items as $item) {
                $item['blocking'] = !$result['completed'] && !$coursemet && !$groupmet[$type] && !$item['met'];
                $result['blocking'] += (int) $item['blocking'];
                $result['criteria'][] = $item;
            }
        }
        $result['pendingaggregation'] = !$result['completed'] && $coursemet;
        return $result;
    }

    /**
     * Title of a criterion, tolerating broken references (deleted activity or course).
     *
     * @param \completion_criteria $criterion
     * @return string
     */
    private static function safe_title(\completion_criteria $criterion): string {
        try {
            return (string) $criterion->get_title_detailed();
        } catch (\Throwable $e) {
            return $criterion->get_type_title();
        }
    }

    /**
     * Completion details of a required activity for the learner.
     *
     * @param \course_modinfo $modinfo Modinfo built for the learner.
     * @param int $cmid
     * @param int $userid
     * @return array|null Null when the activity no longer exists.
     */
    private static function explain_activity(\course_modinfo $modinfo, int $cmid, int $userid): ?array {
        $cms = $modinfo->get_cms();
        if (!isset($cms[$cmid])) {
            return null;
        }
        $cm = $cms[$cmid];
        $activity = [
            'cmid' => $cmid,
            'name' => $cm->get_formatted_name(),
            'modname' => get_string('modulename', $cm->modname),
            'url' => $cm->url ? $cm->url->out(false) : null,
            'visible' => (bool) $cm->uservisible,
            'available' => (bool) $cm->available,
            'availableinfo' => '',
            'state' => COMPLETION_INCOMPLETE,
            'rules' => [],
            'scorm' => null,
        ];
        if (!$cm->available && $cm->availableinfo) {
            $activity['availableinfo'] = \core_availability\info::format_info($cm->availableinfo, $cm->get_course());
        }
        $details = \core_completion\cm_completion_details::get_instance($cm, $userid);
        $activity['state'] = $details->get_overall_completion();
        foreach ($details->get_details() as $rule => $detail) {
            $activity['rules'][] = ['rule' => $rule, 'description' => $detail->description,
                'complete' => in_array((int) $detail->status, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true),
                'failed' => in_array((int) $detail->status, [COMPLETION_COMPLETE_FAIL, COMPLETION_COMPLETE_FAIL_HIDDEN], true)];
        }
        if ($cm->modname === 'scorm') {
            $activity['scorm'] = self::scorm_state((int) $cm->instance, $userid);
        }
        return $activity;
    }

    /**
     * Groups whose members the current user may diagnose in a course.
     *
     * @param \stdClass $course
     * @return int[]|null Null for everyone (no separate groups, or access to all groups); an empty list
     *                    when the user is in no group.
     */
    public static function get_visible_groupids(\stdClass $course): ?array {
        global $USER;
        $context = \context_course::instance($course->id);
        if (groups_get_course_groupmode($course) != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $context)) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($course->id, $USER->id)));
    }

    /**
     * Search the learners of a course the current user can see (separate groups respected), by name
     * and by the identity fields the user may see (for example email), so learners with the same name
     * can be told apart.
     *
     * @param \stdClass $course
     * @param string $search
     * @param int $limit
     * @return \stdClass[] Users with name fields, identity fields and "identity" (the identity values joined).
     */
    public static function search_learners(\stdClass $course, string $search, int $limit = 20): array {
        global $DB;
        $groupids = self::get_visible_groupids($course);
        if ($groupids === []) {
            return [];
        }
        $context = \context_course::instance($course->id);
        $identityfields = \core_user\fields::get_identity_fields($context, false);
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, '', $groupids ?? 0, false);
        [$searchsql, $searchparams] = users_search_sql($search, 'u', USER_SEARCH_CONTAINS, $identityfields, []);
        $fields = \core_user\fields::for_name()->including(...$identityfields)->get_sql('u', false, '', '', false)->selects;
        $matches = $DB->get_records_sql(
            "SELECT u.id, {$fields}
               FROM {user} u
               JOIN ({$enrolsql}) e ON e.id = u.id
              WHERE {$searchsql}
           ORDER BY u.lastname, u.firstname, u.id",
            $enrolparams + $searchparams,
            0,
            $limit
        );
        foreach ($matches as $match) {
            $match->identity = implode(', ', array_filter(array_map(
                fn($field) => (string) ($match->$field ?? ''),
                $identityfields
            )));
        }
        return $matches;
    }

    /**
     * Make sure the current user may diagnose a learner: enrolled in the course, and in a group the
     * current user can see when the course uses separate groups.
     *
     * @param \stdClass $course
     * @param int $userid
     * @throws \moodle_exception
     */
    public static function require_can_diagnose(\stdClass $course, int $userid): void {
        $context = \context_course::instance($course->id);
        if (!is_enrolled($context, $userid)) {
            throw new \moodle_exception('learner:notenrolled', 'local_completiondoctor');
        }
        if (!groups_user_groups_visible($course, $userid)) {
            throw new \moodle_exception('learner:othergroup', 'local_completiondoctor');
        }
    }

    /**
     * What the learner's SCORM attempts reported, across all attempts and SCOs, and what the activity
     * requires. Moodle's completion counts any attempt and any SCO, so each element shows the best value
     * reported: the best status, the highest score.
     *
     * @param int $scormid
     * @param int $userid
     * @return array Keys attempts, values (element => best value), requiredstatus, requiredscore.
     */
    private static function scorm_state(int $scormid, int $userid): array {
        global $DB;
        $scorm = $DB->get_record('scorm', ['id' => $scormid], 'id, completionstatusrequired, completionscorerequired');
        $attempts = $DB->count_records('scorm_attempt', ['scormid' => $scormid, 'userid' => $userid]);
        $state = [
            'attempts' => $attempts,
            'values' => [],
            'requiredstatus' => (int) $scorm->completionstatusrequired,
            'requiredscore' => $scorm->completionscorerequired,
        ];
        if (!$attempts) {
            return $state;
        }
        [$insql, $params] = $DB->get_in_or_equal(self::SCORM_ELEMENTS, SQL_PARAMS_NAMED);
        $params += ['scormid' => $scormid, 'userid' => $userid];
        $rows = $DB->get_recordset_sql(
            "SELECT v.id, e.element, v.value
               FROM {scorm_attempt} a
               JOIN {scorm_scoes_value} v ON v.attemptid = a.id
               JOIN {scorm_element} e ON e.id = v.elementid
              WHERE a.scormid = :scormid AND a.userid = :userid AND e.element {$insql}",
            $params
        );
        foreach ($rows as $row) {
            $current = $state['values'][$row->element] ?? null;
            if ($current === null || self::better($row->element, (string) $row->value, (string) $current)) {
                $state['values'][$row->element] = $row->value;
            }
        }
        $rows->close();
        return $state;
    }

    /**
     * Whether a reported value is better than the current one: a higher score or a better status.
     *
     * @param string $element
     * @param string $value
     * @param string $current
     * @return bool
     */
    private static function better(string $element, string $value, string $current): bool {
        if (str_ends_with($element, '.raw')) {
            return is_numeric($value) && (!is_numeric($current) || (float) $value > (float) $current);
        }
        $rank = fn(string $status) => array_search(strtolower($status), self::STATUS_RANK, true);
        $new = $rank($value);
        $old = $rank($current);
        return $new !== false && ($old === false || $new < $old);
    }
}
