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

namespace local_chronifycompliance\local;

use local_chronifycompliance\task\audit_course;

#[\PHPUnit\Framework\Attributes\CoversClass(setup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\form\setup_form::class)]
/**
 * Tests of the first run setup.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\local\setup
 * @covers \local_chronifycompliance\form\setup_form
 */
final class setup_test extends \advanced_testcase {
    /**
     * Suggested frameworks by sector and country.
     */
    public function test_suggest_frameworks(): void {
        $this->assertSame(['nistcsf2', 'ferpa'], setup::suggest_frameworks('highered', 'US'));
        $this->assertSame(['nistcsf2', 'ferpa'], setup::suggest_frameworks('k12', 'US'));
        $this->assertSame(['nistcsf2'], setup::suggest_frameworks('corporate', 'US'));
        $this->assertSame(['nistcsf2', 'gdpr'], setup::suggest_frameworks('highered', 'DE'));
        $this->assertSame(['nistcsf2', 'gdpr'], setup::suggest_frameworks('government', 'GB'));
        $this->assertSame(['nistcsf2'], setup::suggest_frameworks('nonprofit', 'AU'));
        $this->assertSame(['nistcsf2'], setup::suggest_frameworks('', ''));
    }

    /**
     * Answers are stored; new step 1 answers reset the framework choice to the new suggestions.
     */
    public function test_save_answers(): void {
        $this->resetAfterTest();
        $this->assertSame('', setup::get_sector());
        setup::save_site('highered', 'US');
        $this->assertSame('highered', setup::get_sector());
        $this->assertSame('US', setup::get_country());
        $this->assertSame(['nistcsf2', 'ferpa'], setup::get_frameworks());

        setup::save_frameworks(['gdpr', 'unknown', 'nistcsf2']);
        $this->assertSame(['nistcsf2', 'gdpr'], setup::get_frameworks(), 'Known frameworks only, in display order');
        setup::save_frameworks([]);
        $this->assertSame([], setup::get_frameworks(), 'Choosing none is kept');

        setup::save_site('highered', 'US');
        $this->assertSame([], setup::get_frameworks(), 'Same answers keep the choice');
        setup::save_site('corporate', 'FR');
        $this->assertSame(['nistcsf2', 'gdpr'], setup::get_frameworks());

        $this->expectException(\invalid_parameter_exception::class);
        setup::save_site('space', 'US');
    }

    /**
     * The wizard opens until it is finished or skipped, and never once courses were checked.
     */
    public function test_needs_wizard(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertTrue(setup::needs_wizard());
        setup::skip();
        $this->assertFalse(setup::needs_wizard());
        $this->assertFalse(setup::is_done());

        unset_config('setupskipped', 'local_chronifycompliance');
        $DB->insert_record('local_chronifycompliance_audit', ['courseid' => 5, 'timeaudited' => time()]);
        $this->assertFalse(setup::needs_wizard(), 'Sites with results skip the wizard');
    }

    /**
     * Start checks the site and queues a scan whose first batch is the sample of recently changed courses.
     */
    public function test_start(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('scanbatchsize', 15, 'local_chronifycompliance');
        $courses = [];
        for ($i = 0; $i < 30; $i++) {
            $courses[] = (int) $this->getDataGenerator()->create_course(['enablecompletion' => 1])->id;
        }
        $hidden = (int) $this->getDataGenerator()->create_course(['visible' => 0])->id;
        // The oldest courses changed last, so they make the sample.
        foreach (array_slice($courses, 0, 20) as $n => $courseid) {
            $DB->set_field('course', 'timemodified', time() + 100 - $n, ['id' => $courseid]);
        }
        $DB->set_field('course', 'timemodified', time() + 1000, ['id' => $hidden]);
        $sample = setup::sample_courses();
        $this->assertCount(setup::SAMPLE, $sample);
        $this->assertEqualsCanonicalizing(array_slice($courses, 0, 20), $sample);

        $scanid = setup::start(2);
        $this->assertNotNull($scanid);
        $this->assertTrue(setup::is_done());
        $this->assertFalse(setup::needs_wizard());
        $this->assertSame(31, (int) scan_manager::get_scan($scanid)->totalitems, 'Every course once');

        $batches = [];
        foreach (\core\task\manager::get_adhoc_tasks(audit_course::class) as $task) {
            $data = $task->get_custom_data();
            $batches[$data->batch] = $data->courseids;
        }
        ksort($batches);
        $this->assertEqualsCanonicalizing($sample, array_merge($batches[0], $batches[1]));
        $all = array_merge(...array_values($batches));
        $this->assertCount(31, array_unique($all));
        $this->assertNull(setup::start(2), 'No second scan while one runs');
    }

    /**
     * Each step of the form builds.
     */
    public function test_form_steps(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/chronifycompliance/setup.php');
        foreach ([1, 2, 3] as $step) {
            $form = new \local_chronifycompliance\form\setup_form(null, ['step' => $step]);
            $html = $form->render();
            $this->assertStringNotContainsString('[[', $html);
            $this->assertStringContainsString('name="skip"', $html);
        }
        $step2 = new \local_chronifycompliance\form\setup_form(null, ['step' => 2]);
        $this->assertStringContainsString('name="fw_ferpa"', $step2->render());
    }
}
