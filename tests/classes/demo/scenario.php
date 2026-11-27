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

namespace local_completiondoctor\tests\demo;

/**
 * A demo course set up so that one check (or none, for the healthy course) reports a finding.
 *
 * Scenarios live in tests/classes/demo/scenario (namespace tests\demo\scenario) and are discovered automatically.
 * They are used by tests/cli/seed_demo.php and by the PHPUnit tests of the checks.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class scenario {
    /**
     * Short id, also used in the course short name. The id of the check it triggers, or "healthy".
     *
     * @return string
     */
    abstract public function get_id(): string;

    /**
     * Ids of the checks expected to report findings on this course (empty for the healthy course).
     *
     * @return string[]
     */
    abstract public function get_expected_checks(): array;

    /**
     * Create the course.
     *
     * @param \testing_data_generator $generator
     * @param int $categoryid
     * @return \stdClass The course.
     */
    abstract public function create(\testing_data_generator $generator, int $categoryid): \stdClass;

    /**
     * Full name of the demo course: "Demo: <check name>".
     *
     * @return string
     */
    protected function get_fullname(): string {
        $check = \local_completiondoctor\check\check_registry::get_check($this->get_id());
        return get_string('demo:course', 'local_completiondoctor', $check ? $check->get_name() : $this->get_id());
    }

    /**
     * Whether the standard log can be read, which some checks need.
     *
     * @return bool
     */
    public static function standard_log_available(): bool {
        $readers = get_log_manager()->get_readers('\\core\\log\\sql_internal_table_reader');
        return isset($readers['logstore_standard']);
    }

    /**
     * Create a correctly configured graded assignment (pass grade required, grade to pass 50).
     *
     * @param \testing_data_generator $generator
     * @param int $courseid
     * @param array $overrides Extra module settings.
     * @return \stdClass Module instance with cmid.
     */
    protected function create_graded_assign(
        \testing_data_generator $generator,
        int $courseid,
        array $overrides = []
    ): \stdClass {
        return $generator->create_module('assign', $overrides + [
            'course' => $courseid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
            'gradepass' => 50,
            'grade' => 100,
        ]);
    }

    /**
     * Create a SCORM activity from one of the plugin's synthetic test packages.
     *
     * @param \testing_data_generator $generator
     * @param int $courseid
     * @param string $fixture Package name in tests/fixtures/packages, without .zip.
     * @param array $overrides Module settings.
     * @return \stdClass Module instance with cmid.
     */
    protected function create_scorm(
        \testing_data_generator $generator,
        int $courseid,
        string $fixture,
        array $overrides = []
    ): \stdClass {
        $path = \core_component::get_component_directory('local_completiondoctor') . "/tests/fixtures/packages/{$fixture}.zip";
        return $generator->create_module('scorm', $overrides + [
            'course' => $courseid,
            'packagefilepath' => $path,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionstatusrequired' => 4,
            'hidetoc' => 3,
            'height' => 700,
        ]);
    }

    /**
     * Require activities for course completion (all of them).
     *
     * @param int $courseid
     * @param int[] $cmids
     */
    protected function require_activities(int $courseid, array $cmids): void {
        global $CFG;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        $criterion = new \completion_criteria_activity();
        $config = (object) ['id' => $courseid, 'criteria_activity' => array_fill_keys($cmids, 1)];
        $criterion->update_config($config);
        $this->set_aggregation($courseid);
    }

    /**
     * Add a criterion row of any type.
     *
     * @param int $courseid
     * @param int $type COMPLETION_CRITERIA_TYPE_* constant.
     * @param array $fields Other fields of course_completion_criteria.
     * @return int Criterion id.
     */
    protected function add_criterion(int $courseid, int $type, array $fields = []): int {
        global $DB;
        $id = (int) $DB->insert_record(
            'course_completion_criteria',
            (object) (['course' => $courseid, 'criteriatype' => $type] + $fields)
        );
        $this->set_aggregation($courseid);
        return $id;
    }

    /**
     * Set the overall aggregation to "all" when not set yet.
     *
     * @param int $courseid
     */
    protected function set_aggregation(int $courseid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/completion/completion_aggregation.php');
        if (!$DB->record_exists('course_completion_aggr_methd', ['course' => $courseid, 'criteriatype' => null])) {
            $aggregation = new \completion_aggregation(['course' => $courseid, 'criteriatype' => null,
                'method' => COMPLETION_AGGREGATION_ALL]);
            $aggregation->save();
        }
    }

    /**
     * Course record defaults for a demo course.
     *
     * @param int $categoryid
     * @param string $fullname
     * @param string $summary
     * @return array
     */
    protected function course_defaults(int $categoryid, string $fullname, string $summary = ''): array {
        return [
            'category' => $categoryid,
            'fullname' => $fullname,
            'shortname' => 'cd-demo-' . $this->get_id(),
            'idnumber' => 'cd-demo-' . $this->get_id(),
            'summary' => $summary,
            'enablecompletion' => 1,
        ];
    }
}
