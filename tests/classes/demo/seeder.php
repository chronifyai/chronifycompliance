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
 * Creates demo courses, one per scenario, in a dedicated category.
 *
 * Refuses to run on sites with more than MAX_USERS users, so it can never touch a production site.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seeder {
    /** @var int Largest number of users a site may have for the seeder to run. */
    public const MAX_USERS = 100;
    /** @var string Id number of the demo category. */
    public const CATEGORY_IDNUMBER = 'cd-demo';

    /**
     * Throw if the site is too large to be a test site.
     *
     * @throws \moodle_exception
     */
    public static function assert_test_site(): void {
        global $DB;
        $users = $DB->count_records('user', ['deleted' => 0]);
        if ($users > self::MAX_USERS) {
            throw new \moodle_exception('error:toomanyusers', 'local_completiondoctor', '', $users);
        }
    }

    /**
     * All scenarios.
     *
     * @return scenario[] Keyed by id.
     */
    public static function get_scenarios(): array {
        $scenarios = [];
        foreach (glob(__DIR__ . '/scenario/*.php') as $file) {
            $classname = __NAMESPACE__ . '\\scenario\\' . basename($file, '.php');
            if (is_subclass_of($classname, scenario::class)) {
                $scenario = new $classname();
                $scenarios[$scenario->get_id()] = $scenario;
            }
        }
        ksort($scenarios);
        return $scenarios;
    }

    /**
     * Create the demo courses that do not exist yet.
     *
     * @param string[]|null $only Only these scenario ids.
     * @return array Created course ids keyed by scenario id.
     */
    public static function seed(?array $only = null): array {
        global $DB, $CFG;
        self::assert_test_site();
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        // Moodle 5.3 autoloads the PHPUnit helper; 4.5 needs the file.
        if (class_exists(\core\test\phpunit\phpunit_util::class)) {
            $generator = \core\test\phpunit\phpunit_util::get_data_generator();
        } else {
            require_once($CFG->libdir . '/phpunit/classes/util.php');
            $generator = \phpunit_util::get_data_generator();
        }

        $category = $DB->get_record('course_categories', ['idnumber' => self::CATEGORY_IDNUMBER]);
        if (!$category) {
            $category = $generator->create_category([
                'name' => get_string('demo:category', 'local_completiondoctor'),
                'idnumber' => self::CATEGORY_IDNUMBER,
            ]);
        }

        $created = [];
        foreach (self::get_scenarios() as $id => $scenario) {
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            if ($DB->record_exists('course', ['idnumber' => 'cd-demo-' . $id])) {
                continue;
            }
            $course = $scenario->create($generator, (int) $category->id);
            $created[$id] = (int) $course->id;
        }
        return $created;
    }

    /**
     * Delete the demo category and its courses.
     *
     * @return int Number of deleted courses.
     */
    public static function purge(): int {
        global $DB;
        self::assert_test_site();
        $category = $DB->get_record('course_categories', ['idnumber' => self::CATEGORY_IDNUMBER]);
        if (!$category) {
            return 0;
        }
        $count = $DB->count_records('course', ['category' => $category->id]);
        \core_course_category::get($category->id, MUST_EXIST, true)->delete_full(false);
        return $count;
    }
}
