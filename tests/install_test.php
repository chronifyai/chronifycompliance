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

namespace local_chronifycompliance;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Installation smoke tests for Chronify.Compliance.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class install_test extends \advanced_testcase {
    /**
     * All capabilities are installed with the expected context levels.
     */
    public function test_capabilities_installed(): void {
        $expected = [
            'local/chronifycompliance:viewcoursereport' => CONTEXT_COURSE,
            'local/chronifycompliance:viewlearnerdiagnostic' => CONTEXT_COURSE,
            'local/chronifycompliance:ignorefinding' => CONTEXT_COURSE,
            'local/chronifycompliance:applyfix' => CONTEXT_COURSE,
            'local/chronifycompliance:viewsitereport' => CONTEXT_SYSTEM,
            'local/chronifycompliance:runscan' => CONTEXT_SYSTEM,
        ];
        foreach ($expected as $name => $contextlevel) {
            $capability = get_capability_info($name);
            $this->assertNotEmpty($capability, $name);
            $this->assertEquals($contextlevel, $capability->contextlevel, $name);
        }
    }

    /**
     * No role gets the capabilities by default.
     */
    public function test_no_archetype_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $users = [];
        foreach (['student', 'teacher', 'editingteacher', 'manager'] as $role) {
            $users[$role] = $generator->create_and_enrol($course, $role);
        }

        $capabilities = $DB->get_fieldset_select('capabilities', 'name', 'component = ?', ['local_chronifycompliance']);
        $this->assertCount(7, $capabilities);
        foreach ($capabilities as $capability) {
            foreach ($users as $role => $user) {
                $this->assertFalse(has_capability($capability, $context, $user), "{$role}: {$capability}");
            }
        }
    }
}
