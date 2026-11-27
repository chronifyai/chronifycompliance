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

namespace local_chronifycompliance\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fix_log;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\local\scan_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Privacy provider tests.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass User who ignored and fixed. */
    private \stdClass $user1;
    /** @var \stdClass User who undid a fix and started a scan. */
    private \stdClass $user2;

    /**
     * Create data for two users.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->user1 = $generator->create_user();
        $this->user2 = $generator->create_user();

        $a = new finding('x', (int) $this->course->id, 2, 'm', [], [], null, false, ['k' => 'a']);
        $b = new finding('x', (int) $this->course->id, 2, 'm', [], [], null, false, ['k' => 'b']);
        finding_store::sync((int) $this->course->id, [$a, $b], ['x']);
        finding_store::ignore($a->id, 'Not relevant', (int) $this->user1->id);
        $logid = fix_log::record($b, 'demo', ['v' => 0], ['v' => 1], (int) $this->user1->id);
        fix_log::mark_undone($logid, (int) $this->user2->id);
        scan_manager::start_scan((int) $this->user2->id);
    }

    /**
     * Contexts of each user.
     */
    public function test_get_contexts_for_userid(): void {
        $coursecontext = \context_course::instance($this->course->id);
        $contexts = provider::get_contexts_for_userid((int) $this->user1->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$coursecontext->id], $contexts);

        $contexts = provider::get_contexts_for_userid((int) $this->user2->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$coursecontext->id, \context_system::instance()->id], $contexts);
    }

    /**
     * Users in each context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist(\context_course::instance($this->course->id), 'local_chronifycompliance');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->user1->id, $this->user2->id], $userlist->get_userids());

        $userlist = new userlist(\context_system::instance(), 'local_chronifycompliance');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->user2->id], $userlist->get_userids());
    }

    /**
     * Export contains the ignore reason and the fix.
     */
    public function test_export_user_data(): void {
        $context = \context_course::instance($this->course->id);
        $this->export_context_data_for_user((int) $this->user1->id, $context, 'local_chronifycompliance');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_chronifycompliance')]);
        $this->assertSame('Not relevant', $data->ignored[0]['reason']);
        $this->assertCount(1, $data->fixes);
    }

    /**
     * Deleting one user anonymizes only that user.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $context = \context_course::instance($this->course->id);
        provider::delete_data_for_user(new approved_contextlist($this->user1, 'local_chronifycompliance', [$context->id]));
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_finding', ['ignoredby' => $this->user1->id]));
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_fixlog', ['userid' => $this->user1->id]));
        $this->assertSame(1, $DB->count_records('local_chronifycompliance_fixlog', ['undoneby' => $this->user2->id]));
        $this->assertSame(1, $DB->count_records('local_chronifycompliance_fixlog', []), 'Audit rows are kept');
    }

    /**
     * Deleting users in the system context anonymizes scans.
     */
    public function test_delete_data_for_users_and_context(): void {
        global $DB;
        $system = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'local_chronifycompliance', [$this->user2->id]));
        $this->assertSame(0, $DB->count_records('local_chronifycompliance_scan', ['startedby' => $this->user2->id]));

        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));
        $this->assertSame(0, $DB->count_records_select('local_chronifycompliance_fixlog', 'userid > 0 OR undoneby > 0'));
        $this->assertSame(0, $DB->count_records_select('local_chronifycompliance_finding', 'ignoredby > 0'));
    }
}
