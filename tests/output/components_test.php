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

namespace local_chronifycompliance\output;

use local_chronifycompliance\data\refresher;

#[\PHPUnit\Framework\Attributes\CoversClass(workspace_nav::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(data_age::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(invitation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(component_gallery::class)]
/**
 * Tests of the shared page components: top navigation, data age line and invitations.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\output\workspace_nav
 * @covers \local_chronifycompliance\output\data_age
 * @covers \local_chronifycompliance\output\invitation
 * @covers \local_chronifycompliance\output\component_gallery
 */
final class components_test extends \advanced_testcase {
    /**
     * Render a component with the real template.
     *
     * @param \renderable $component
     * @return string
     */
    private function render(\renderable $component): string {
        global $PAGE;
        $PAGE->set_url('/local/chronifycompliance/components.php');
        $PAGE->set_context(\context_system::instance());
        $html = $PAGE->get_renderer('core')->render($component);
        $this->assertStringNotContainsString('[[', $html);
        return $html;
    }

    /**
     * The navigation lists only pages that exist, and marks the current one.
     */
    public function test_workspace_nav(): void {
        $this->resetAfterTest();
        $pages = workspace_nav::get_pages();
        $this->assertArrayHasKey('dashboard', $pages);
        foreach (array_keys($pages) as $key) {
            $this->assertArrayHasKey($key, workspace_nav::PAGES);
        }
        $html = $this->render(new workspace_nav('dashboard'));
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current'));
    }

    /**
     * Data age states: never, fresh, refreshing after Refresh now, failed.
     */
    public function test_data_age_states(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = $GLOBALS['PAGE']->get_renderer('core');

        $data = (new data_age('activity', null, true))->export_for_template($output);
        $this->assertFalse($data['hastime']);
        $this->assertTrue($data['canrefresh']);
        $this->assertStringContainsString('data-action="cc-refresh"', $this->render(new data_age('activity', null, true)));

        $now = time();
        $DB->insert_record('local_chronifycompliance_refresh', (object) [
            'dataset' => 'activity', 'status' => refresher::STATUS_IDLE, 'highwater' => 0, 'rowsread' => 0,
            'batches' => 0, 'runtime' => 0, 'failures' => 0, 'timestarted' => $now - 60,
            'timefinished' => $now - 30, 'timemodified' => $now - 30,
        ]);
        $data = (new data_age('activity', null, true))->export_for_template($output);
        $this->assertTrue($data['hastime']);
        $this->assertSame(data_age::format_time($now - 30), $data['time']);
        $this->assertFalse($data['refreshing']);

        refresher::request('activity');
        $data = (new data_age('activity', null, true))->export_for_template($output);
        $this->assertTrue($data['refreshing']);
        $this->assertFalse($data['canrefresh']);

        $DB->set_field('local_chronifycompliance_refresh', 'status', refresher::STATUS_FAILED, ['dataset' => 'activity']);
        $this->assertTrue((new data_age('activity'))->export_for_template($output)['failed']);

        // Without a dataset there is no Refresh now.
        $data = (new data_age(null, $now, true))->export_for_template($output);
        $this->assertFalse($data['canrefresh']);
        $this->assertTrue($data['hastime']);
    }

    /**
     * Today, yesterday and older times.
     */
    public function test_format_time(): void {
        $this->resetAfterTest();
        $midnight = usergetmidnight(time());
        $this->assertStringEndsWith(' today', data_age::format_time($midnight + 1));
        $this->assertStringEndsWith(' yesterday', data_age::format_time($midnight - 1));
        $older = data_age::format_time($midnight - 3 * DAYSECS);
        $this->assertStringNotContainsString('today', $older);
        $this->assertStringNotContainsString('yesterday', $older);
    }

    /**
     * Invitations: shown by default, hidden for 30 days after dismissal, hidden for all when turned off.
     */
    public function test_invitation(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $url = new \moodle_url('https://chronifyai.com');

        $invite = invitation::for_page('dashboard', $url);
        $this->assertNotNull($invite);
        $html = $this->render($invite);
        $this->assertStringContainsString('data-preference="local_chronifycompliance_invite_dashboard"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);

        set_user_preference(invitation::preference('dashboard'), time() - DAYSECS);
        $this->assertNull(invitation::for_page('dashboard', $url));
        $this->assertNotNull(invitation::for_page('completion', $url));

        set_user_preference(invitation::preference('dashboard'), time() - invitation::DISMISS_FOR - 1);
        $this->assertNotNull(invitation::for_page('dashboard', $url));

        set_config('invitations', 0, 'local_chronifycompliance');
        $this->assertNull(invitation::for_page('completion', $url));

        // Every key has its strings and a registered preference the user may set.
        $preferences = local_chronifycompliance_user_preferences();
        foreach (invitation::KEYS as $key) {
            $this->assertArrayHasKey(invitation::preference($key), $preferences);
            $this->assertTrue(get_string_manager()->string_exists('invite:' . $key, 'local_chronifycompliance'));
            $this->assertTrue(get_string_manager()->string_exists('invite:' . $key . ':link', 'local_chronifycompliance'));
        }

        $this->expectException(\coding_exception::class);
        invitation::for_page('unknown', $url);
    }

    /**
     * The component gallery renders every state without missing strings.
     */
    public function test_gallery_page(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/chronifycompliance/components.php');
        $PAGE->set_context(\context_system::instance());
        $html = component_gallery::render($PAGE->get_renderer('core'));
        $this->assertStringContainsString('data-region="undo-toast"', $html);
        $this->assertStringContainsString('data-region="invitation"', $html);
        $this->assertSame(6, substr_count($html, 'data-region="data-age"'));
        $this->assertSame(3, substr_count($html, 'data-region="finding"'));
        $this->assertStringContainsString('data-action="cd-undo-fix"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Load lib.php for the preference callback.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/local/chronifycompliance/lib.php');
    }
}
