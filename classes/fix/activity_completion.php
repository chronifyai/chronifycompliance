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
 * Changes the completion rules of one activity the way core's bulk completion edit does
 * (course_modules and the module's own completion fields, the course_module_updated event and a
 * course cache rebuild), without resetting learners' completion states: learners are evaluated
 * against the new rules the next time their progress in the activity changes.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_completion extends \core_completion\manager {
    /** @var string[] Completion fields stored in course_modules. */
    public const CM_FIELDS = ['completion', 'completionview', 'completionexpected', 'completiongradeitemnumber',
        'completionpassgrade'];

    /**
     * Change the completion rules of an activity.
     *
     * @param int $cmid
     * @param array $cmfields Values of CM_FIELDS to change.
     * @param array $instancefields Completion fields of the module's own table to change.
     */
    public static function update(int $cmid, array $cmfields, array $instancefields = []): void {
        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $data = array_intersect_key($cmfields, array_flip(self::CM_FIELDS)) + $instancefields;
        if (array_key_exists('completiongradeitemnumber', $data)) {
            // Core's form field: "use grade" maps to grade item 0 or none.
            $data['completionusegrade'] = $data['completiongradeitemnumber'] === null ? 0 : 1;
            unset($data['completiongradeitemnumber']);
        }
        $data += ['completion' => (int) $cm->completion];
        (new self($courseid))->apply_completion_cm($cm, $data, (bool) $instancefields);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Current values of completion fields of an activity, to compare before an undo.
     *
     * @param int $cmid
     * @param string[] $cmfields
     * @param string $modname
     * @param string[] $instancefields
     * @return array
     */
    public static function current(int $cmid, array $cmfields, string $modname = '', array $instancefields = []): array {
        global $DB;
        $values = [];
        if ($cmfields) {
            $values = (array) $DB->get_record('course_modules', ['id' => $cmid], implode(', ', $cmfields), MUST_EXIST);
        }
        if ($instancefields) {
            $instance = $DB->get_field('course_modules', 'instance', ['id' => $cmid], MUST_EXIST);
            $values += (array) $DB->get_record($modname, ['id' => $instance], implode(', ', $instancefields), MUST_EXIST);
        }
        return array_map(fn($value) => $value === null ? null : (int) $value, $values);
    }
}
