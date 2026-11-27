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

namespace local_completiondoctor\fix;

/**
 * Changes to course completion criteria used by fixes, through core's completion criteria objects,
 * followed by core's "course completion settings updated" event. Only called from check fix methods,
 * which run inside the fixer's transaction.
 *
 * Changing criteria does not recalculate completion: learners who already completed the course stay
 * completed, and other learners are evaluated against the new criteria the next time their progress
 * changes (an activity completion, a grade). Undo works on the course and activity, not on row ids,
 * because saving the completion settings page recreates every criterion with a new id.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class criteria_editor {
    /**
     * Load core's completion criteria classes.
     */
    private static function load(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_grade.php');
    }

    /**
     * Whether an activity is a course completion criterion.
     *
     * @param int $courseid
     * @param int $cmid
     * @return bool
     */
    public static function has_activity_criterion(int $courseid, int $cmid): bool {
        global $DB;
        return $DB->record_exists('course_completion_criteria', ['course' => $courseid,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'moduleinstance' => $cmid]);
    }

    /**
     * Add an activity to the course completion criteria.
     *
     * @param int $courseid
     * @param int $cmid
     * @return bool False when it was already a criterion.
     */
    public static function add_activity_criterion(int $courseid, int $cmid): bool {
        global $DB;
        self::load();
        if (self::has_activity_criterion($courseid, $cmid)) {
            return false;
        }
        $modname = $DB->get_field_sql(
            "SELECT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = :cmid AND cm.course = :c",
            ['cmid' => $cmid, 'c' => $courseid],
            MUST_EXIST
        );
        $criterion = new \completion_criteria_activity();
        $criterion->course = $courseid;
        $criterion->criteriatype = COMPLETION_CRITERIA_TYPE_ACTIVITY;
        $criterion->module = $modname;
        $criterion->moduleinstance = $cmid;
        $criterion->insert();
        self::notify($courseid);
        return true;
    }

    /**
     * Remove an activity from the course completion criteria.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array|null The removed row, null when the activity was not a criterion.
     */
    public static function remove_activity_criterion(int $courseid, int $cmid): ?array {
        self::load();
        $criterion = \completion_criteria_activity::fetch(['course' => $courseid, 'moduleinstance' => $cmid]);
        if (!$criterion) {
            return null;
        }
        $row = ['course' => (int) $criterion->course, 'criteriatype' => (int) $criterion->criteriatype,
            'module' => $criterion->module, 'moduleinstance' => (int) $criterion->moduleinstance];
        $criterion->delete();
        self::notify($courseid);
        return $row;
    }

    /**
     * Restore a removed activity criterion (undo).
     *
     * @param array $row As returned by remove_activity_criterion().
     * @return bool False when an equal criterion exists again (nothing to restore).
     */
    public static function restore_activity_criterion(array $row): bool {
        self::load();
        if (self::has_activity_criterion((int) $row['course'], (int) $row['moduleinstance'])) {
            return false;
        }
        $criterion = new \completion_criteria_activity();
        foreach (['course', 'criteriatype', 'module', 'moduleinstance'] as $field) {
            $criterion->$field = $row[$field];
        }
        $criterion->insert();
        self::notify((int) $row['course']);
        return true;
    }

    /**
     * The grade to pass of the course grade criterion.
     *
     * @param int $courseid
     * @return float|null Null when the course has no grade criterion.
     */
    public static function get_grade_criterion(int $courseid): ?float {
        self::load();
        $criterion = \completion_criteria_grade::fetch(['course' => $courseid]);
        return $criterion ? (float) $criterion->gradepass : null;
    }

    /**
     * Change the grade to pass of the course grade criterion.
     *
     * @param int $courseid
     * @param float $gradepass
     */
    public static function set_grade_criterion(int $courseid, float $gradepass): void {
        self::load();
        $criterion = \completion_criteria_grade::fetch(['course' => $courseid]);
        if (!$criterion) {
            throw new \moodle_exception('error:undochanged', 'local_completiondoctor');
        }
        $criterion->gradepass = $gradepass;
        $criterion->update();
        self::notify($courseid);
    }

    /**
     * Tell the rest of Moodle the course completion settings changed (logs, caches, other plugins).
     *
     * @param int $courseid
     */
    private static function notify(int $courseid): void {
        \core\event\course_completion_updated::create([
            'courseid' => $courseid,
            'context' => \context_course::instance($courseid),
        ])->trigger();
    }

    /**
     * Learners not yet complete who have met every other condition, for the preview of removing one:
     * they complete the next time their progress is evaluated.
     *
     * @param int $courseid
     * @param int $criterionid Condition being removed.
     * @param int $others Number of the other conditions.
     * @return int
     */
    public static function count_ready_without(int $courseid, int $criterionid, int $others): int {
        global $DB;
        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM (SELECT cc.userid
                       FROM {course_completion_crit_compl} cc
                  LEFT JOIN {course_completions} c ON c.course = cc.course AND c.userid = cc.userid
                      WHERE cc.course = :courseid AND cc.criteriaid <> :criterionid AND c.timecompleted IS NULL
                   GROUP BY cc.userid
                     HAVING COUNT(DISTINCT cc.criteriaid) >= :others) ready",
            ['courseid' => $courseid, 'criterionid' => $criterionid, 'others' => $others]
        );
    }

    /**
     * Number of learners with any course completion progress, for preview warnings.
     *
     * @param int $courseid
     * @return int
     */
    public static function count_learners_with_progress(int $courseid): int {
        global $DB;
        return $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {course_completion_crit_compl} WHERE course = :courseid",
            ['courseid' => $courseid]
        );
    }
}
