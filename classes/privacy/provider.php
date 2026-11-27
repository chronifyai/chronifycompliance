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

namespace local_completiondoctor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Completion Doctor.
 *
 * The plugin stores no learner data. It keeps the id of users who ignored a finding, applied or
 * undid a fix, started a scan or requested a link check, and of the user who last changed each
 * record (usermodified), as an audit trail of configuration changes. Deleting a user's data
 * anonymizes these ids (sets them to 0) so the audit trail and undo information survive.
 * Notifications about new Critical findings are stored by the core message subsystem.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /** @var array User id columns of tables with a course id (the site id means the system context). */
    private const COURSE_TABLES = [
        'local_completiondoctor_finding' => ['ignoredby', 'usermodified'],
        'local_completiondoctor_fixlog' => ['userid', 'undoneby', 'usermodified'],
        'local_completiondoctor_linkcheck' => ['requestedby', 'usermodified'],
        'local_completiondoctor_audit' => ['usermodified'],
        'local_completiondoctor_link' => ['usermodified'],
    ];
    /** @var array User id columns of site-wide tables (system context). */
    private const SITE_TABLES = [
        'local_completiondoctor_scan' => ['startedby', 'usermodified'],
        'local_completiondoctor_pkg' => ['usermodified'],
    ];

    /**
     * Describe the stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_completiondoctor_finding', [
            'ignoredby' => 'privacy:metadata:finding:ignoredby',
            'ignorereason' => 'privacy:metadata:finding:ignorereason',
            'timeignored' => 'privacy:metadata:finding:timeignored',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:finding');
        $collection->add_database_table('local_completiondoctor_fixlog', [
            'userid' => 'privacy:metadata:fixlog:userid',
            'undoneby' => 'privacy:metadata:fixlog:undoneby',
            'timecreated' => 'privacy:metadata:fixlog:timecreated',
            'timeundone' => 'privacy:metadata:fixlog:timeundone',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:fixlog');
        $collection->add_database_table('local_completiondoctor_scan', [
            'startedby' => 'privacy:metadata:scan:startedby',
            'timestarted' => 'privacy:metadata:scan:timestarted',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:scan');
        $collection->add_database_table('local_completiondoctor_linkcheck', [
            'requestedby' => 'privacy:metadata:linkcheck:requestedby',
            'timecreated' => 'privacy:metadata:linkcheck:timecreated',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:linkcheck');
        foreach (['audit', 'link', 'pkg'] as $table) {
            $collection->add_database_table('local_completiondoctor_' . $table, [
                'usermodified' => 'privacy:metadata:usermodified',
                'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:' . $table);
        }
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * Contexts holding data of the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $system = false;
        foreach (self::COURSE_TABLES as $table => $fields) {
            foreach ($fields as $field) {
                $contextlist->add_from_sql(
                    "SELECT ctx.id
                       FROM {{$table}} t
                       JOIN {context} ctx ON ctx.instanceid = t.courseid AND ctx.contextlevel = :level
                      WHERE t.{$field} = :userid AND t.courseid <> :siteid",
                    ['userid' => $userid, 'level' => CONTEXT_COURSE, 'siteid' => SITEID]
                );
                $system = $system || $DB->record_exists($table, [$field => $userid, 'courseid' => SITEID]);
            }
        }
        foreach (self::SITE_TABLES as $table => $fields) {
            foreach ($fields as $field) {
                $system = $system || $DB->record_exists($table, [$field => $userid]);
            }
        }
        if ($system) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $courseid = self::get_courseid($context);
        if ($courseid === null) {
            return;
        }
        foreach (self::COURSE_TABLES as $table => $fields) {
            foreach ($fields as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT {$field} FROM {{$table}} WHERE courseid = :courseid AND {$field} > 0",
                    ['courseid' => $courseid]
                );
            }
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            foreach (self::SITE_TABLES as $table => $fields) {
                foreach ($fields as $field) {
                    $userlist->add_from_sql($field, "SELECT {$field} FROM {{$table}} WHERE {$field} > 0", []);
                }
            }
        }
    }

    /**
     * Export the data of the user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $subcontext = [get_string('pluginname', 'local_completiondoctor')];

        foreach ($contextlist->get_contexts() as $context) {
            $courseid = self::get_courseid($context);
            if ($courseid === null) {
                continue;
            }
            $data = ['ignored' => [], 'fixes' => [], 'scans' => [], 'linkchecks' => [], 'changed' => []];

            $findings = $DB->get_records(
                'local_completiondoctor_finding',
                ['courseid' => $courseid, 'ignoredby' => $userid],
                'id',
                'id, checkid, cmid, ignorereason, timeignored'
            );
            foreach ($findings as $finding) {
                $data['ignored'][] = [
                    'check' => $finding->checkid,
                    'cmid' => $finding->cmid,
                    'reason' => $finding->ignorereason,
                    'timeignored' => transform::datetime($finding->timeignored),
                ];
            }

            $logs = $DB->get_records_select(
                'local_completiondoctor_fixlog',
                'courseid = :courseid AND (userid = :userid OR undoneby = :userid2)',
                ['courseid' => $courseid, 'userid' => $userid, 'userid2' => $userid],
                'id'
            );
            foreach ($logs as $log) {
                $data['fixes'][] = [
                    'check' => $log->checkid,
                    'fixtype' => $log->fixtype,
                    'cmid' => $log->cmid,
                    'appliedbyyou' => transform::yesno($log->userid == $userid),
                    'timecreated' => transform::datetime($log->timecreated),
                    'undonebyyou' => transform::yesno($log->undoneby == $userid),
                    'timeundone' => $log->timeundone ? transform::datetime($log->timeundone) : null,
                ];
            }

            $checks = $DB->get_records(
                'local_completiondoctor_linkcheck',
                ['courseid' => $courseid, 'requestedby' => $userid],
                'id',
                'id, status, timecreated'
            );
            foreach ($checks as $check) {
                $data['linkchecks'][] = [
                    'status' => $check->status,
                    'timecreated' => transform::datetime($check->timecreated),
                ];
            }

            $tables = self::COURSE_TABLES;
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $scans = $DB->get_records(
                    'local_completiondoctor_scan',
                    ['startedby' => $userid],
                    'id',
                    'id, type, status, timestarted'
                );
                foreach ($scans as $scan) {
                    $data['scans'][] = [
                        'type' => $scan->type,
                        'status' => $scan->status,
                        'timestarted' => transform::datetime($scan->timestarted),
                    ];
                }
                $tables += self::SITE_TABLES;
            }
            foreach (array_keys($tables) as $table) {
                $params = ['usermodified' => $userid];
                if (isset(self::COURSE_TABLES[$table])) {
                    $params['courseid'] = $courseid;
                }
                $count = $DB->count_records($table, $params);
                if ($count) {
                    $data['changed'][] = ['records' => substr($table, strlen('local_completiondoctor_')), 'count' => $count];
                }
            }

            $data = array_filter($data);
            if ($data) {
                writer::with_context($context)->export_data($subcontext, (object) $data);
            }
        }
    }

    /**
     * Anonymize all user references in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $courseid = self::get_courseid($context);
        if ($courseid !== null) {
            self::anonymize($courseid, null, $context->contextlevel == CONTEXT_SYSTEM);
        }
    }

    /**
     * Anonymize the references to one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $courseid = self::get_courseid($context);
            if ($courseid !== null) {
                self::anonymize($courseid, [$userid], $context->contextlevel == CONTEXT_SYSTEM);
            }
        }
    }

    /**
     * Anonymize the references to several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $courseid = self::get_courseid($context);
        if ($courseid !== null && $userlist->get_userids()) {
            self::anonymize($courseid, $userlist->get_userids(), $context->contextlevel == CONTEXT_SYSTEM);
        }
    }

    /**
     * Course id stored for a context: the course for course contexts, the site for the system context.
     *
     * @param \context $context
     * @return int|null Null when the plugin stores nothing for this context level.
     */
    private static function get_courseid(\context $context): ?int {
        if ($context->contextlevel == CONTEXT_COURSE) {
            return (int) $context->instanceid;
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            return (int) SITEID;
        }
        return null;
    }

    /**
     * Replace user ids by 0.
     *
     * @param int $courseid Course (or site) whose rows are affected.
     * @param int[]|null $userids Only these users, null for everyone.
     * @param bool $includesite Also anonymize the site-wide tables (system context).
     */
    private static function anonymize(int $courseid, ?array $userids, bool $includesite): void {
        global $DB;
        $tables = self::COURSE_TABLES + ($includesite ? self::SITE_TABLES : []);
        foreach ($tables as $table => $fields) {
            foreach ($fields as $field) {
                $where = "{$field} > 0";
                $params = [];
                if (isset(self::COURSE_TABLES[$table])) {
                    $where .= ' AND courseid = :courseid';
                    $params['courseid'] = $courseid;
                }
                if ($userids !== null) {
                    [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                    $where .= " AND {$field} {$insql}";
                    $params += $inparams;
                }
                $DB->set_field_select($table, $field, 0, $where, $params);
            }
        }
    }
}
