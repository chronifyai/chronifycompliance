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

namespace local_chronifycompliance\data;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_chronifycompliance\data\datasets\activity;
use local_chronifycompliance\privacy\provider;
use local_chronifycompliance\task\cleanup;

#[\PHPUnit\Framework\Attributes\CoversClass(activity::class)]
/**
 * Tests for the activity dataset: users, logins, course activity and completions from the standard log.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\data\datasets\activity
 */
final class activity_test extends \advanced_testcase {
    /** @var activity */
    private activity $dataset;

    /**
     * Empty log, real transactions, no pause between batches.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $DB->delete_records('logstore_standard_log');
        set_config('refreshpace', 0, 'local_chronifycompliance');
        $this->dataset = new activity();
    }

    /**
     * Add a row to the standard log.
     *
     * @param string $event Event name without namespace, for example user_loggedin.
     * @param int $userid
     * @param int $courseid
     * @param int $time
     * @param int $relateduserid
     */
    private function log(string $event, int $userid, int $courseid, int $time, int $relateduserid = 0): void {
        global $DB;
        $DB->insert_record('logstore_standard_log', [
            'eventname' => '\\core\\event\\' . $event,
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'course',
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => \context_system::instance()->id,
            'contextlevel' => CONTEXT_SYSTEM,
            'contextinstanceid' => 0,
            'userid' => $userid,
            'courseid' => $courseid,
            'relateduserid' => $relateduserid ?: null,
            'anonymous' => 0,
            'timecreated' => $time,
            'origin' => 'web',
        ]);
    }

    /**
     * A time on a given day, at noon in the server time zone.
     *
     * @param int $daysago
     * @return int
     */
    private function noon(int $daysago): int {
        return usergetmidnight(time() - $daysago * DAYSECS, \core_date::get_server_timezone()) + 12 * HOURSECS;
    }

    /**
     * The registry finds the dataset.
     */
    public function test_registered(): void {
        $this->assertInstanceOf(activity::class, dataset_registry::get_dataset('activity'));
    }

    /**
     * One refresh fills events per user, course and day, site totals, and completions.
     */
    public function test_summarises_log(): void {
        global $DB, $CFG;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $day1 = $this->noon(2);
        $day2 = $this->noon(1);

        $this->log('user_loggedin', $u1->id, 0, $day1);
        $this->log('course_viewed', $u1->id, $course->id, $day1);
        $this->log('course_viewed', $u1->id, $course->id, $day1 + 60);
        $this->log('course_viewed', $u2->id, $course->id, $day1);
        $this->log('user_login_failed', 0, 0, $day1);
        $this->log('course_viewed', (int) $CFG->siteguest, $course->id, $day1);
        $this->log('course_completed', $u2->id, $course->id, $day1 + 120, $u2->id);
        $this->log('course_viewed', $u1->id, $course->id, $day2);

        $outcome = refresher::refresh($this->dataset, ['batchsize' => 3]);
        $this->assertSame(refresher::RESULT_DONE, $outcome->result);
        $this->assertSame(8, $outcome->rows);

        $d1 = $this->dataset->day($day1);
        $d2 = $this->dataset->day($day2);
        $events = fn(int $day, int $userid, int $courseid) => (int) $DB->get_field(
            activity::TABLE_ACTDAY,
            'events',
            ['day' => $day, 'userid' => $userid, 'courseid' => $courseid]
        );
        $this->assertSame(1, $events($d1, $u1->id, SITEID), 'The login counts as site activity.');
        $this->assertSame(2, $events($d1, $u1->id, $course->id));
        $this->assertSame(2, $events($d1, $u2->id, $course->id), 'A view and a completion.');
        $this->assertSame(1, $events($d2, $u1->id, $course->id));
        $this->assertFalse($DB->record_exists(activity::TABLE_ACTDAY, ['userid' => $CFG->siteguest]), 'Guests are left out.');

        $site = $DB->get_record(activity::TABLE_SITEDAY, ['day' => $d1]);
        $this->assertEquals(2, $site->activeusers);
        $this->assertEquals(5, $site->events);
        $this->assertEquals(1, $site->logins);
        $this->assertEquals(1, $site->failedlogins);
        $this->assertEquals(1, $site->completions);
        $this->assertEquals(1, $DB->get_field(activity::TABLE_SITEDAY, 'activeusers', ['day' => $d2]));

        $this->assertEquals(
            $day1 + 120,
            $DB->get_field(activity::TABLE_COMPL, 'timecompleted', ['courseid' => $course->id, 'userid' => $u2->id])
        );
    }

    /**
     * Later refreshes add to the existing rows instead of duplicating them.
     */
    public function test_incremental_adds_up(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $time = $this->noon(1);
        $this->log('course_viewed', $user->id, $course->id, $time);
        refresher::refresh($this->dataset);

        $this->log('course_viewed', $user->id, $course->id, $time + 10);
        $this->log('course_completed', $user->id, $course->id, $time + 20, $user->id);
        refresher::refresh($this->dataset);
        $this->log('course_completed', $user->id, $course->id, $time + 30, $user->id);
        refresher::refresh($this->dataset);

        $this->assertSame(1, $DB->count_records(activity::TABLE_ACTDAY));
        $this->assertEquals(4, $DB->get_field(activity::TABLE_ACTDAY, 'events', []));
        $this->assertEquals(4, $DB->get_field(activity::TABLE_SITEDAY, 'events', []));
        $this->assertSame(1, $DB->count_records(activity::TABLE_COMPL));
        $this->assertEquals($time + 30, $DB->get_field(activity::TABLE_COMPL, 'timecompleted', []), 'The latest completion wins.');
    }

    /**
     * The first load starts at the first log row of the first load period.
     */
    public function test_first_load_period(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->log('course_viewed', $user->id, SITEID, $this->noon(120));
        $this->log('course_viewed', $user->id, SITEID, $this->noon(100));
        $this->log('course_viewed', $user->id, SITEID, $this->noon(10));
        set_config('refreshfirstload', 90, 'local_chronifycompliance');

        $outcome = refresher::refresh($this->dataset);
        $this->assertSame(1, $outcome->rows);
        $this->assertSame(1, $DB->count_records(activity::TABLE_SITEDAY));

        // A log with nothing in the period starts after its newest row.
        $DB->delete_records('logstore_standard_log');
        $this->log('course_viewed', $user->id, SITEID, $this->noon(200));
        $newest = (int) $DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $this->assertSame($newest, $this->dataset->get_start());
    }

    /**
     * Days follow the server time zone.
     */
    public function test_day_uses_server_timezone(): void {
        $this->setTimezone('America/New_York');
        // 2026-03-02 03:00 UTC is still March 1 in New York.
        $this->assertSame(20260301, (new activity())->day(gmmktime(3, 0, 0, 3, 2, 2026)));
        $this->setTimezone('Europe/London');
        $this->assertSame(20260302, (new activity())->day(gmmktime(3, 0, 0, 3, 2, 2026)));
    }

    /**
     * Deleting a course removes its rows; the cleanup task removes days past the retention period.
     */
    public function test_course_deleted_and_retention(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->log('course_viewed', $user->id, $course->id, $this->noon(20));
        $this->log('course_completed', $user->id, $course->id, $this->noon(20), $user->id);
        $this->log('course_viewed', $user->id, SITEID, $this->noon(1));
        refresher::refresh($this->dataset);

        delete_course($course, false);
        $this->assertFalse($DB->record_exists(activity::TABLE_ACTDAY, ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists(activity::TABLE_COMPL, ['courseid' => $course->id]));

        set_config('refreshkeep', 10, 'local_chronifycompliance');
        $this->assertSame(2, $DB->count_records(activity::TABLE_SITEDAY));
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertSame(1, $DB->count_records(activity::TABLE_SITEDAY), 'Only the recent day is kept.');
    }

    /**
     * Privacy: contexts, export and deletion of a learner's activity and completions.
     */
    public function test_privacy(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $time = $this->noon(1);
        $this->log('course_viewed', $user->id, $course->id, $time);
        $this->log('course_completed', $user->id, $course->id, $time, $user->id);
        $this->log('user_loggedin', $user->id, 0, $time);
        $this->log('course_viewed', $other->id, $course->id, $time);
        refresher::refresh($this->dataset);

        $coursecontext = \context_course::instance($course->id);
        $contexts = provider::get_contexts_for_userid($user->id)->get_contextids();
        $this->assertContainsEquals($coursecontext->id, $contexts);
        $this->assertContainsEquals(\context_system::instance()->id, $contexts);

        provider::export_user_data(new approved_contextlist($user, 'local_chronifycompliance', [$coursecontext->id]));
        $data = writer::with_context($coursecontext)->get_data([get_string('pluginname', 'local_chronifycompliance')]);
        $this->assertSame(2, $data->activity[0]['events']);
        $this->assertNotEmpty($data->completion['timecompleted']);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_chronifycompliance',
            [$coursecontext->id, \context_system::instance()->id]
        ));
        $this->assertFalse($DB->record_exists(activity::TABLE_ACTDAY, ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists(activity::TABLE_COMPL, ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists(activity::TABLE_ACTDAY, ['userid' => $other->id]), 'Other users stay.');
    }
}
