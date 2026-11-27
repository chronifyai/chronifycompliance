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

namespace local_completiondoctor\task;

use local_completiondoctor\check\base_check;
use local_completiondoctor\check\finding;
use local_completiondoctor\local\finding_store;

/**
 * Sends a popup notification to the people who can see the course report when a course gets new
 * Critical findings. Queued by finding_store::sync() only when the setting "notifyteachers" is on,
 * so checks themselves never send messages.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_teachers extends \core\task\adhoc_task {
    /** @var int Most people notified per course. */
    public const MAX_RECIPIENTS = 50;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:notifyteachers', 'local_completiondoctor');
    }

    /**
     * Queue a notification about new Critical findings of a course.
     *
     * @param int $courseid
     * @param int[] $findingids
     */
    public static function queue(int $courseid, array $findingids): void {
        if (!$findingids || !get_config('local_completiondoctor', 'notifyteachers')) {
            return;
        }
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'findingids' => array_values(array_map('intval', $findingids))]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Send the notification for the findings that are still open and Critical.
     */
    public function execute(): void {
        global $DB;
        if (!get_config('local_completiondoctor', 'notifyteachers')) {
            return;
        }
        $data = $this->get_custom_data();
        $course = $DB->get_record('course', ['id' => (int) $data->courseid]);
        if (!$course) {
            return;
        }
        $findings = array_filter(
            array_map(fn($id) => finding_store::get((int) $id, IGNORE_MISSING), (array) $data->findingids),
            fn(?finding $f) => $f && $f->is_open() && $f->severity === base_check::SEVERITY_CRITICAL
        );
        if (!$findings) {
            return;
        }
        $context = \context_course::instance($course->id);
        $recipients = get_enrolled_users(
            $context,
            'local/completiondoctor:viewcoursereport',
            0,
            'u.*',
            null,
            0,
            self::MAX_RECIPIENTS,
            true
        );
        $url = new \moodle_url('/local/completiondoctor/course.php', ['id' => $course->id]);
        $coursename = format_string($course->fullname, true, ['context' => $context]);
        $sent = 0;
        foreach ($recipients as $user) {
            \core\cron::setup_user($user, $course);
            $a = (object) ['course' => $coursename, 'count' => count($findings),
                'problems' => implode('; ', array_map(fn(finding $f) => $f->get_message(), $findings))];
            $message = new \core\message\message();
            $message->component = 'local_completiondoctor';
            $message->name = 'criticalfinding';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->courseid = $course->id;
            $message->notification = 1;
            $message->subject = get_string('notify:subject', 'local_completiondoctor', $a);
            $message->fullmessage = get_string('notify:body', 'local_completiondoctor', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('report:title', 'local_completiondoctor');
            if (message_send($message)) {
                $sent++;
            }
        }
        \core\cron::setup_user();
        mtrace("Course {$course->id}: " . count($findings) . " new critical findings, {$sent} people notified.");
    }
}
