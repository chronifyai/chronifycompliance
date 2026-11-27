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
 * Development tool: fill the local test site with a large amount of data, to measure the data layer.
 *
 * PostgreSQL only (uses generate_series). Not part of the plugin release.
 *
 * Usage, from the Moodle directory:
 *   php local/chronifycompliance/dev/seed-large-site.php --users=100000 --courses=3000 --logs=10000000 --days=120
 *
 * Log rows come in sessions of 25 events (one user, one course, one day), like real use.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

[$options] = cli_get_params(
    ['users' => 100000, 'courses' => 3000, 'logs' => 10000000, 'days' => 120, 'logsonly' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    echo "Options: --users --courses --logs --days, --logsonly to add log rows for the seed users and courses already there\n";
    exit(0);
}
if ($DB->get_dbfamily() !== 'postgres') {
    cli_error('This tool needs PostgreSQL.');
}

$users = (int) $options['users'];
$courses = (int) $options['courses'];
$logs = (int) $options['logs'];
$days = (int) $options['days'];
$start = microtime(true);
$elapsed = fn() => round(microtime(true) - $start) . 's';

if ($options['logsonly']) {
    $firstuser = (int) $DB->get_field_sql("SELECT MIN(id) FROM {user} WHERE username LIKE 'seed%'");
    $users = (int) $DB->get_field_sql("SELECT MAX(id) FROM {user} WHERE username LIKE 'seed%'") - $firstuser + 1;
    $firstcourse = (int) $DB->get_field_sql("SELECT MIN(id) FROM {course} WHERE shortname LIKE 'seed%'");
    $courses = (int) $DB->get_field_sql("SELECT MAX(id) FROM {course} WHERE shortname LIKE 'seed%'") - $firstcourse + 1;
    if (!$firstuser || !$firstcourse) {
        cli_error('No seed users or courses yet.');
    }
} else {
// Users, straight in SQL.
$prefix = 'seed' . time() . '_';
$password = hash_internal_user_password('Seed123!');
$DB->execute(
    "INSERT INTO {user} (auth, confirmed, mnethostid, username, password, firstname, lastname, email, timecreated, timemodified)
     SELECT 'manual', 1, :host, :prefix || g, :password, 'Seed', 'User ' || g, :prefix2 || g || '@example.com', :now, :now2
       FROM generate_series(1, :count) g",
    [
        'host' => $CFG->mnet_localhost_id, 'prefix' => $prefix, 'prefix2' => $prefix, 'password' => $password,
        'now' => time(), 'now2' => time(), 'count' => $users,
    ]
);
$firstuser = (int) $DB->get_field_sql('SELECT MIN(id) FROM {user} WHERE username LIKE :p', ['p' => $prefix . '%']);
cli_writeln("Users: {$users} from id {$firstuser} ({$elapsed()})");

// Courses, through the Moodle API so they are complete.
$category = \core_course_category::create(['name' => 'Seed ' . $prefix]);
$courseids = [];
for ($i = 1; $i <= $courses; $i++) {
    $course = create_course((object) [
        'fullname' => "Seed course {$i}",
        'shortname' => $prefix . 'c' . $i,
        'category' => $category->id,
        'enablecompletion' => 1,
    ]);
    $courseids[] = (int) $course->id;
    if ($i % 250 === 0) {
        cli_writeln("Courses: {$i} ({$elapsed()})");
    }
}
$firstcourse = min($courseids);
cli_writeln("Courses: {$courses} from id {$firstcourse} ({$elapsed()})");
}

// Log rows: sessions of 25 events, oldest first so ids grow with time as on a real site.
$systemcontext = context_system::instance()->id;
$sessions = intdiv($logs, 25);
$now = time();
$batch = 200000;
$slices = max(1, (int) ceil($sessions / $batch));
$slice = intdiv($days * DAYSECS, $slices);
for ($done = 0, $i = 0; $done < $sessions; $done += $batch, $i++) {
    $count = min($batch, $sessions - $done);
    $sliceend = $now - ($slices - 1 - $i) * $slice;
    $DB->execute(
        "INSERT INTO {logstore_standard_log}
                (eventname, component, action, target, crud, edulevel, contextid, contextlevel, contextinstanceid,
                 userid, courseid, anonymous, timecreated, origin)
         SELECT CASE WHEN e.n = 1 THEN '\\core\\event\\user_loggedin' ELSE '\\core\\event\\course_viewed' END,
                'core', 'viewed', 'course', 'r', 2, :ctx, 10, 0,
                s.userid, CASE WHEN e.n = 1 THEN 0 ELSE s.courseid END, 0, s.t + e.n * 30, 'web'
           FROM (SELECT :firstuser + floor(random() * :users)::int AS userid,
                        :firstcourse + floor(random() * :courses)::int AS courseid,
                        :sliceend - floor(random() * :span)::int AS t
                   FROM generate_series(1, :count)) s
     CROSS JOIN generate_series(1, 25) AS e(n)
       ORDER BY s.t + e.n * 30",
        [
            'ctx' => $systemcontext, 'firstuser' => $firstuser, 'users' => $users,
            'firstcourse' => $firstcourse, 'courses' => $courses, 'sliceend' => $sliceend, 'span' => $slice, 'count' => $count,
        ]
    );
    cli_writeln('Log rows: ' . (($done + $count) * 25) . " ({$elapsed()})");
}
cli_writeln("Done in {$elapsed()}.");
