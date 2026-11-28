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

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\persistent\finding_record;

/**
 * Persistence of findings. Writes only to the plugin's own tables.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding_store {
    /** @var string Findings table. */
    public const TABLE = finding_record::TABLE;

    /**
     * Store the result of an audit.
     *
     * New findings are inserted as open. Known findings are refreshed; resolved or fixed ones that are
     * detected again are reopened, ignored ones stay ignored. Open findings of the checks that ran and
     * that were not detected again become resolved, and so do open findings of checks the administrator
     * turned off. Findings of other checks that did not run (for example after an error) are untouched.
     *
     * @param int $courseid Course id (SITEID for site audits).
     * @param finding[] $findings Findings detected now.
     * @param string[] $checkidsran Ids of the checks that ran successfully.
     * @param int|null $now Audit time.
     * @return array Counts with keys new, updated, reopened and resolved.
     */
    public static function sync(int $courseid, array $findings, array $checkidsran, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $stats = ['new' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];
        // New or reopened Critical findings, for the optional teacher notification. Not on the first audit.
        $critical = [];
        $notify = $courseid !== SITEID && get_config('local_chronifycompliance', 'notifyteachers')
            && audit_store::get($courseid) !== null;

        $existing = [];
        if ($checkidsran) {
            [$insql, $params] = $DB->get_in_or_equal($checkidsran, SQL_PARAMS_NAMED);
            $params['courseid'] = $courseid;
            $records = $DB->get_records_select(self::TABLE, "courseid = :courseid AND checkid {$insql}", $params);
            foreach ($records as $record) {
                $existing[$record->fingerprint] = $record;
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $seen = [];
        foreach ($findings as $finding) {
            if ($finding->courseid !== $courseid || !in_array($finding->checkid, $checkidsran, true)) {
                throw new \coding_exception('Finding does not belong to this audit: ' . $finding->checkid);
            }
            $fingerprint = $finding->get_fingerprint();
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            if (isset($existing[$fingerprint])) {
                $current = finding::from_record($existing[$fingerprint]);
                $finding->id = $current->id;
                $finding->timecreated = $current->timecreated;
                $finding->status = $current->status;
                $finding->ignorereason = $current->ignorereason;
                $finding->ignoredby = $current->ignoredby;
                $finding->timeignored = $current->timeignored;
                if (in_array($current->status, [finding::STATUS_RESOLVED, finding::STATUS_FIXED], true)) {
                    $finding->status = finding::STATUS_OPEN;
                    $stats['reopened']++;
                    $critical[] = $finding;
                } else {
                    $stats['updated']++;
                }
                $finding->lastseen = $now;
                $record = new finding_record($current->id);
                $record->from_record(self::persistent_fields($finding));
                $record->update();
            } else {
                $finding->status = finding::STATUS_OPEN;
                $finding->lastseen = $now;
                $record = new finding_record(0, self::persistent_fields($finding));
                $finding->id = (int) $record->create()->get('id');
                $stats['new']++;
                $critical[] = $finding;
            }
        }

        foreach ($existing as $fingerprint => $record) {
            if (!isset($seen[$fingerprint]) && $record->status === finding::STATUS_OPEN) {
                (new finding_record((int) $record->id))->set('status', finding::STATUS_RESOLVED)->update();
                $stats['resolved']++;
            }
        }
        $disabled = array_keys(array_filter(check_registry::get_checks(), fn($check) => !$check->is_enabled()));
        if ($disabled) {
            [$insql, $params] = $DB->get_in_or_equal($disabled, SQL_PARAMS_NAMED);
            $stats['resolved'] += $DB->count_records_select(
                self::TABLE,
                "courseid = :courseid AND status = :open AND checkid {$insql}",
                $params + ['courseid' => $courseid, 'open' => finding::STATUS_OPEN]
            );
            self::bulk_update(
                'status = :resolved',
                "courseid = :courseid AND status = :open AND checkid {$insql}",
                $params + ['resolved' => finding::STATUS_RESOLVED, 'courseid' => $courseid, 'open' => finding::STATUS_OPEN]
            );
        }
        $transaction->allow_commit();

        self::invalidate_summary($courseid);
        if ($notify) {
            $ids = array_map(
                fn(finding $f) => (int) $f->id,
                array_filter($critical, fn(finding $f) => $f->severity === base_check::SEVERITY_CRITICAL)
            );
            \local_chronifycompliance\task\notify_teachers::queue($courseid, $ids);
        }
        return $stats;
    }

    /**
     * Get a finding by id.
     *
     * @param int $id
     * @param int $strictness MUST_EXIST or IGNORE_MISSING.
     * @return finding|null
     */
    public static function get(int $id, int $strictness = MUST_EXIST): ?finding {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', $strictness);
        return $record ? finding::from_record($record) : null;
    }

    /**
     * Get the findings of a course.
     *
     * @param int $courseid
     * @param string[]|null $statuses Only these statuses, null for all.
     * @return finding[] Keyed by id, most severe first.
     */
    public static function get_course_findings(int $courseid, ?array $statuses = [finding::STATUS_OPEN]): array {
        global $DB;
        $where = 'courseid = :courseid';
        $params = ['courseid' => $courseid];
        if ($statuses !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED);
            $where .= " AND status {$insql}";
            $params += $inparams;
        }
        $findings = [];
        foreach ($DB->get_records_select(self::TABLE, $where, $params, 'severity DESC, checkid, id') as $record) {
            $findings[$record->id] = finding::from_record($record);
        }
        return $findings;
    }

    /**
     * Mark a finding as ignored.
     *
     * @param int $id
     * @param string $reason
     * @param int $userid
     * @return finding
     */
    public static function ignore(int $id, string $reason, int $userid): finding {
        $finding = self::get($id);
        (new finding_record($id))->set_many([
            'status' => finding::STATUS_IGNORED,
            'ignorereason' => $reason,
            'ignoredby' => $userid,
            'timeignored' => time(),
        ])->update();
        self::invalidate_summary($finding->courseid);
        audit_store::refresh_counts($finding->courseid);
        return self::get($id);
    }

    /**
     * Set the status of a finding (used by the fixer and to un-ignore).
     *
     * @param int $id
     * @param string $status One of the finding::STATUS_* constants.
     */
    public static function set_status(int $id, string $status): void {
        $finding = self::get($id);
        $values = ['status' => $status];
        if ($status !== finding::STATUS_IGNORED) {
            $values += ['ignorereason' => null, 'ignoredby' => null, 'timeignored' => null];
        }
        (new finding_record($id))->set_many($values)->update();
        self::invalidate_summary($finding->courseid);
        audit_store::refresh_counts($finding->courseid);
    }

    /**
     * Delete all findings of a course (course deleted).
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
        audit_store::delete_course($courseid);
        self::invalidate_summary($courseid);
    }

    /**
     * Fields of a finding for its persistent (the persistent sets the times and the user).
     *
     * @param finding $finding
     * @return \stdClass
     */
    private static function persistent_fields(finding $finding): \stdClass {
        $record = $finding->to_record();
        unset($record->id, $record->timecreated, $record->timemodified);
        return $record;
    }

    /**
     * Callback of the per-check settings ("enabled_<check>" and "severity_<check>").
     *
     * @param string $fullname Full setting name, for example s_local_chronifycompliance_severity_nocriteria.
     */
    public static function setting_changed(string $fullname): void {
        if (!preg_match('/(enabled|severity)_([a-z][a-z0-9]*)$/', $fullname, $matches)) {
            return;
        }
        check_registry::reset();
        if ($matches[1] === 'severity') {
            self::apply_severity_setting($matches[2]);
        } else {
            self::apply_enabled_setting($matches[2]);
        }
    }

    /**
     * Apply the administrator's severity setting of a check to all its stored findings at once.
     *
     * @param string $checkid
     */
    public static function apply_severity_setting(string $checkid): void {
        $check = check_registry::get_check($checkid);
        $override = $check ? $check->get_severity_override() : null;
        if ($override) {
            self::bulk_update('severity = :severity', 'checkid = :checkid', ['severity' => $override, 'checkid' => $checkid]);
        } else {
            self::bulk_update('severity = baseseverity', 'checkid = :checkid', ['checkid' => $checkid]);
        }
        self::after_bulk_change();
    }

    /**
     * Resolve the open findings of a check the administrator turned off.
     *
     * @param string $checkid
     */
    public static function apply_enabled_setting(string $checkid): void {
        $check = check_registry::get_check($checkid);
        if ($check && !$check->is_enabled()) {
            self::bulk_update(
                'status = :resolved',
                'checkid = :checkid AND status = :open',
                ['resolved' => finding::STATUS_RESOLVED, 'checkid' => $checkid, 'open' => finding::STATUS_OPEN]
            );
            self::after_bulk_change();
        }
    }

    /**
     * Update many findings in one statement, moving their modification time and user like the
     * persistent does (the cleanup task deletes resolved findings by their modification time).
     *
     * @param string $set SET clause without the timestamps.
     * @param string $select WHERE clause.
     * @param array $params Named parameters of both.
     */
    private static function bulk_update(string $set, string $select, array $params): void {
        global $DB, $USER;
        $DB->execute(
            "UPDATE {" . self::TABLE . "} SET {$set}, timemodified = :bulknow, usermodified = :bulkuser WHERE {$select}",
            $params + ['bulknow' => time(), 'bulkuser' => (int) ($USER->id ?? 0)]
        );
    }

    /**
     * Refresh the cached and stored counts of all courses.
     */
    private static function after_bulk_change(): void {
        \cache::make('local_chronifycompliance', 'coursesummary')->purge();
        audit_store::refresh_all_counts();
    }

    /**
     * Counts of open findings by severity, cached.
     *
     * @param int $courseid
     * @return array Keys critical, warning, info, total and lastseen.
     */
    public static function get_summary(int $courseid): array {
        global $DB;
        $cache = \cache::make('local_chronifycompliance', 'coursesummary');
        $summary = $cache->get($courseid);
        if ($summary !== false) {
            return $summary;
        }
        $rows = $DB->get_records_sql(
            "SELECT severity, COUNT(1) AS total, MAX(lastseen) AS lastseen
               FROM {" . self::TABLE . "}
              WHERE courseid = :courseid AND status = :status
           GROUP BY severity",
            ['courseid' => $courseid, 'status' => finding::STATUS_OPEN]
        );
        $summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'lastseen' => 0];
        $names = [
            base_check::SEVERITY_CRITICAL => 'critical',
            base_check::SEVERITY_WARNING => 'warning',
            base_check::SEVERITY_INFO => 'info',
        ];
        foreach ($rows as $severity => $row) {
            if (isset($names[$severity])) {
                $summary[$names[$severity]] = (int) $row->total;
                $summary['total'] += (int) $row->total;
                $summary['lastseen'] = max($summary['lastseen'], (int) $row->lastseen);
            }
        }
        $cache->set($courseid, $summary);
        return $summary;
    }

    /**
     * Drop the cached summary of a course.
     *
     * @param int $courseid
     */
    public static function invalidate_summary(int $courseid): void {
        \cache::make('local_chronifycompliance', 'coursesummary')->delete($courseid);
    }
}
