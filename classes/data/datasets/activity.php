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

namespace local_chronifycompliance\data\datasets;

use local_chronifycompliance\data\dataset;

/**
 * Activity: users, logins, course activity and course completions, from the standard log in one pass.
 *
 * Reads logstore_standard_log by id, the only index guaranteed to make "rows after X" cheap, and fills:
 * - local_chronifycompliance_actday: events per user, course and day (the site course for events outside courses);
 * - local_chronifycompliance_siteday: per day, active users, events, logins, failed logins, completions;
 * - local_chronifycompliance_compl: latest completion time per user and course (course_completed events).
 *
 * Days are YYYYMMDD in the server time zone. The first load starts at the first log row of the last
 * "refreshfirstload" days (default 90), so older history is not read.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity extends dataset {
    /** @var string Events per user, course and day. */
    public const TABLE_ACTDAY = 'local_chronifycompliance_actday';
    /** @var string Site totals per day. */
    public const TABLE_SITEDAY = 'local_chronifycompliance_siteday';
    /** @var string Latest completion per user and course. */
    public const TABLE_COMPL = 'local_chronifycompliance_compl';

    /** @var int Default days of history loaded on the first refresh. */
    public const DEFAULT_FIRSTLOAD = 90;
    /** @var int Default days of daily data kept (about 24 months). */
    public const DEFAULT_KEEP = 730;

    /** @var string Successful login. */
    private const EVENT_LOGIN = '\\core\\event\\user_loggedin';
    /** @var string Failed login. */
    private const EVENT_LOGINFAILED = '\\core\\event\\user_login_failed';
    /** @var string Course completed (relateduserid is the learner). */
    private const EVENT_COMPLETED = '\\core\\event\\course_completed';

    /** @var \DateTimeZone|null Server time zone, for days. */
    private ?\DateTimeZone $timezone = null;

    /**
     * Dataset id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'activity';
    }

    /**
     * Start at the first log row of the first load period.
     *
     * @return int
     */
    public function get_start(): int {
        global $DB;
        $days = (int) get_config('local_chronifycompliance', 'refreshfirstload');
        $days = $days > 0 ? $days : self::DEFAULT_FIRSTLOAD;
        $since = time() - $days * DAYSECS;
        $first = $DB->get_field_sql(
            'SELECT MIN(id) FROM {logstore_standard_log} WHERE timecreated >= :since',
            ['since' => $since]
        );
        if ($first) {
            return (int) $first - 1;
        }
        // Nothing in the period: start after the newest row.
        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
    }

    /**
     * Read log rows after the high water mark.
     *
     * @param int $after
     * @param int $limit
     * @return \stdClass[]
     */
    public function read(int $after, int $limit): array {
        global $DB;
        return array_values($DB->get_records_select(
            'logstore_standard_log',
            'id > :after',
            ['after' => $after],
            'id',
            'id AS rowkey, eventname, userid, relateduserid, courseid, timecreated',
            0,
            $limit
        ));
    }

    /**
     * Summarise a batch of log rows and add it to the dataset tables.
     *
     * @param \stdClass[] $rows
     */
    public function store(array $rows): void {
        global $CFG;
        $guestid = (int) ($CFG->siteguest ?? 0);
        $activity = [];
        $site = [];
        $completions = [];

        foreach ($rows as $row) {
            $day = $this->day((int) $row->timecreated);
            $site[$day] ??= ['events' => 0, 'logins' => 0, 'failedlogins' => 0, 'completions' => 0];
            $userid = (int) $row->userid;
            $courseid = (int) $row->courseid > 0 ? (int) $row->courseid : SITEID;

            if ($row->eventname === self::EVENT_LOGINFAILED) {
                $site[$day]['failedlogins']++;
                continue;
            }
            if ($userid <= 0 || $userid === $guestid) {
                continue;
            }
            $site[$day]['events']++;
            $activity[$day][$userid][$courseid] = ($activity[$day][$userid][$courseid] ?? 0) + 1;
            if ($row->eventname === self::EVENT_LOGIN) {
                $site[$day]['logins']++;
            } else if ($row->eventname === self::EVENT_COMPLETED && (int) $row->relateduserid > 0) {
                $site[$day]['completions']++;
                $completions[$courseid][(int) $row->relateduserid] = (int) $row->timecreated;
            }
        }

        foreach ($activity as $day => $users) {
            $this->add_activity($day, $users);
        }
        foreach ($site as $day => $totals) {
            $this->add_site_totals($day, $totals);
        }
        foreach ($completions as $courseid => $learners) {
            $this->set_completions($courseid, $learners);
        }
    }

    /**
     * Delete everything the dataset stored.
     */
    public function purge(): void {
        global $DB;
        $DB->delete_records(self::TABLE_ACTDAY);
        $DB->delete_records(self::TABLE_SITEDAY);
        $DB->delete_records(self::TABLE_COMPL);
    }

    /**
     * Day of a time as YYYYMMDD in the server time zone.
     *
     * @param int $time
     * @return int
     */
    public function day(int $time): int {
        $this->timezone ??= \core_date::get_server_timezone_object();
        return (int) (new \DateTime('@' . $time))->setTimezone($this->timezone)->format('Ymd');
    }

    /**
     * Add event counts of one day.
     *
     * @param int $day
     * @param array<int, array<int, int>> $users User id => course id => events.
     */
    private function add_activity(int $day, array $users): void {
        global $DB;
        foreach (array_chunk(array_keys($users), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['day'] = $day;
            $existing = [];
            $records = $DB->get_recordset_select(
                self::TABLE_ACTDAY,
                "day = :day AND userid {$insql}",
                $params,
                '',
                'id, userid, courseid, events'
            );
            foreach ($records as $record) {
                $existing[$record->userid . '-' . $record->courseid] = $record;
            }
            $records->close();

            $inserts = [];
            foreach ($chunk as $userid) {
                foreach ($users[$userid] as $courseid => $events) {
                    $record = $existing[$userid . '-' . $courseid] ?? null;
                    if ($record) {
                        $DB->set_field(self::TABLE_ACTDAY, 'events', $record->events + $events, ['id' => $record->id]);
                    } else {
                        $inserts[] = ['day' => $day, 'userid' => $userid, 'courseid' => $courseid, 'events' => $events];
                    }
                }
            }
            if ($inserts) {
                $DB->insert_records(self::TABLE_ACTDAY, $inserts);
            }
        }
    }

    /**
     * Add site totals of one day and recount its active users.
     *
     * @param int $day
     * @param array<string, int> $totals
     */
    private function add_site_totals(int $day, array $totals): void {
        global $DB;
        $active = (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT userid) FROM {' . self::TABLE_ACTDAY . '} WHERE day = :day',
            ['day' => $day]
        );
        $record = $DB->get_record(self::TABLE_SITEDAY, ['day' => $day]);
        if (!$record) {
            $DB->insert_record(self::TABLE_SITEDAY, ['day' => $day, 'activeusers' => $active] + $totals);
            return;
        }
        foreach ($totals as $field => $value) {
            $record->$field += $value;
        }
        $record->activeusers = $active;
        $DB->update_record(self::TABLE_SITEDAY, $record);
    }

    /**
     * Record the latest completion time of learners in a course.
     *
     * @param int $courseid
     * @param array<int, int> $learners User id => time completed.
     */
    private function set_completions(int $courseid, array $learners): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_keys($learners), SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $existing = $DB->get_records_select_menu(
            self::TABLE_COMPL,
            "courseid = :courseid AND userid {$insql}",
            $params,
            '',
            'userid, id'
        );
        foreach ($learners as $userid => $time) {
            if (isset($existing[$userid])) {
                $DB->set_field(self::TABLE_COMPL, 'timecompleted', $time, ['id' => $existing[$userid]]);
            } else {
                $DB->insert_record(self::TABLE_COMPL, ['courseid' => $courseid, 'userid' => $userid, 'timecompleted' => $time]);
            }
        }
    }

    /**
     * Delete what the dataset stored for a course (course deleted).
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE_ACTDAY, ['courseid' => $courseid]);
        $DB->delete_records(self::TABLE_COMPL, ['courseid' => $courseid]);
    }

    /**
     * Delete rows older than a number of days.
     *
     * @param int $days
     * @return int Rows deleted.
     */
    public function delete_older_than(int $days): int {
        global $DB;
        $limit = $this->day(time() - $days * DAYSECS);
        $count = $DB->count_records_select(self::TABLE_ACTDAY, 'day < :day', ['day' => $limit])
            + $DB->count_records_select(self::TABLE_SITEDAY, 'day < :day', ['day' => $limit]);
        $DB->delete_records_select(self::TABLE_ACTDAY, 'day < :day', ['day' => $limit]);
        $DB->delete_records_select(self::TABLE_SITEDAY, 'day < :day', ['day' => $limit]);
        return $count;
    }
}
