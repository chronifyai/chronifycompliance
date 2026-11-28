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

/**
 * Behat steps and named pages for Chronify.Compliance.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use local_chronifycompliance\check\course_auditor;

/**
 * Behat steps and named pages for Chronify.Compliance.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_chronifycompliance extends behat_base {
    /**
     * Named selectors: "Finding", the card of a finding, located by its check name.
     *
     * @return behat_component_named_selector[]
     */
    public static function get_partial_named_selectors(): array {
        return [
            new behat_component_named_selector('Finding', [
                "//div[@data-region='finding'][.//h4[contains(normalize-space(.), %locator%)]]",
            ]),
        ];
    }

    /**
     * Create a Chronify.Compliance demo course and audit it. The course short name is "cd-demo-<scenario>".
     *
     * @Given /^the Chronify.Compliance demo course "(?P<scenario_string>[a-z0-9]+)" exists$/
     * @param string $scenario Demo scenario id, for example "nopassgrade".
     */
    public function the_chronify_compliance_demo_course_exists(string $scenario): void {
        global $USER, $DB;
        require_once(__DIR__ . '/../demo_autoload.php');
        $class = '\\local_chronifycompliance\\tests\\demo\\scenario\\' . $scenario;
        if (!class_exists($class)) {
            throw new Exception("Unknown Chronify.Compliance demo scenario '{$scenario}'");
        }
        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $generator = behat_util::get_data_generator();
            $category = $DB->get_record('course_categories', ['idnumber' => 'cd-demo'])
                ?: $generator->create_category(['name' => 'Chronify.Compliance demo', 'idnumber' => 'cd-demo']);
            $course = (new $class())->create($generator, (int) $category->id);
            course_auditor::audit((int) $course->id, true);
        } finally {
            \core\session\manager::set_user($previous);
        }
    }

    /**
     * Put the site in the failing state of a site check's demo scenario, then run the site checks.
     *
     * @Given /^the Chronify.Compliance demo site problem "(?P<check_string>[a-z0-9]+)" exists$/
     * @param string $check Site check id, for example "adminmfa".
     */
    public function the_chronify_compliance_demo_site_problem_exists(string $check): void {
        global $USER;
        require_once(__DIR__ . '/../demo_autoload.php');
        $scenarios = \local_chronifycompliance\tests\demo\site_scenario::get_all();
        if (!isset($scenarios[$check])) {
            throw new Exception("Unknown Chronify.Compliance site scenario '{$check}'");
        }
        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $scenarios[$check]->fail(behat_util::get_data_generator());
            \local_chronifycompliance\check\check_registry::reset();
            \local_chronifycompliance\check\site_auditor::audit(true);
        } finally {
            \core\session\manager::set_user($previous);
        }
    }

    /**
     * Run the scheduled selection of new courses for link checks, as if their last change was long enough ago.
     *
     * @Given /^the Chronify.Compliance link checks of changed new courses are queued$/
     */
    public function the_chronify_compliance_link_checks_are_queued(): void {
        \local_chronifycompliance\links\link_scan::queue_changed_courses(time() + HOURSECS);
    }

    /**
     * Named pages with an identifier.
     *
     * | Page type          | Identifier                     |
     * | Course report      | Course short name              |
     * | Learner diagnostic | Course short name              |
     * | SCORM panel        | SCORM activity name            |
     *
     * @param string $page
     * @param string $identifier
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        switch (strtolower($page)) {
            case 'course report':
                return new moodle_url('/local/chronifycompliance/course.php', ['id' => $this->get_course_id($identifier)]);
            case 'learner diagnostic':
                return new moodle_url('/local/chronifycompliance/learner.php', ['courseid' => $this->get_course_id($identifier)]);
            case 'scorm panel':
                return new moodle_url(
                    '/local/chronifycompliance/scorm.php',
                    ['cmid' => $this->get_cm_by_activity_name('scorm', $identifier)->id]
                );
            default:
                throw new Exception("Unrecognized Chronify.Compliance page type '{$page}'");
        }
    }

    /**
     * Named pages without an identifier: "Dashboard", "Completion".
     *
     * @param string $page
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        if (strtolower($page) === 'dashboard') {
            return new moodle_url('/local/chronifycompliance/index.php');
        }
        if (strtolower($page) === 'completion') {
            return new moodle_url('/local/chronifycompliance/completion.php');
        }
        throw new Exception("Unrecognized Chronify.Compliance page '{$page}'");
    }
}
