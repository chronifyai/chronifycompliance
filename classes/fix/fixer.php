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

namespace local_completiondoctor\fix;

use local_completiondoctor\check\check_registry;
use local_completiondoctor\check\finding;
use local_completiondoctor\local\finding_store;

/**
 * The only component that changes data outside the plugin tables.
 *
 * Every fix is previewed again just before it is applied (so a stale preview is never applied),
 * runs in a transaction and is written to the fix log so it can be undone.
 * Permission checks are the caller's job (UI, web services, CLI --confirm).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixer {
    /**
     * Preview the fix of a finding.
     *
     * @param int $findingid
     * @param array $params User parameters.
     * @return fix_preview
     */
    public static function preview(int $findingid, array $params = []): fix_preview {
        self::load_libraries();
        [$finding, $check] = self::load_fixable($findingid);
        return $check->preview_fix($finding, $params);
    }

    /**
     * Apply the fix of a finding.
     *
     * @param int $findingid
     * @param array $params User parameters, the same as for the preview.
     * @param int $userid User applying the fix.
     * @return fix_result
     */
    public static function apply(int $findingid, array $params, int $userid): fix_result {
        global $DB;
        self::load_libraries();
        [$finding, $check] = self::load_fixable($findingid);
        if ($finding->status !== finding::STATUS_OPEN) {
            throw new \moodle_exception('error:findingnotopen', 'local_completiondoctor');
        }

        $transaction = $DB->start_delegated_transaction();
        $preview = $check->preview_fix($finding, $params);
        $result = $check->apply_fix($finding, $preview, $userid);
        if ($result->success) {
            $after = $result->after ?: $preview->after;
            $result->fixlogid = fix_log::record($finding, $preview->fixtype, $preview->before, $after, $userid);
            finding_store::set_status($finding->id, finding::STATUS_FIXED);
        }
        $transaction->allow_commit();

        if ($result->success) {
            self::after_change($finding->courseid);
            self::log_event(
                \local_completiondoctor\event\fix_applied::class,
                (int) $result->fixlogid,
                $finding->courseid,
                $finding->checkid,
                (int) $finding->id
            );
        }
        return $result;
    }

    /**
     * Undo a fix.
     *
     * @param int $fixlogid
     * @param int $userid User undoing the fix.
     * @return fix_result
     */
    public static function undo(int $fixlogid, int $userid): fix_result {
        global $DB;
        self::load_libraries();
        $log = fix_log::get($fixlogid);
        if ($log->undone) {
            throw new \moodle_exception('error:fixalreadyundone', 'local_completiondoctor');
        }
        $check = check_registry::get_check($log->checkid);
        if (!$check) {
            throw new \moodle_exception('error:checknotfound', 'local_completiondoctor', '', $log->checkid);
        }

        $transaction = $DB->start_delegated_transaction();
        $before = json_decode((string) $log->valuebefore, true) ?: [];
        $after = json_decode((string) $log->valueafter, true) ?: [];
        $result = $check->undo_fix($log->fixtype, $before, $after, $log);
        if ($result->success) {
            fix_log::mark_undone($fixlogid, $userid);
            $finding = finding_store::get((int) $log->findingid, IGNORE_MISSING);
            if ($finding && $finding->status === finding::STATUS_FIXED) {
                finding_store::set_status($finding->id, finding::STATUS_OPEN);
            }
            $result->fixlogid = $fixlogid;
        }
        $transaction->allow_commit();

        if ($result->success) {
            self::after_change((int) $log->courseid);
            self::log_event(
                \local_completiondoctor\event\fix_undone::class,
                $fixlogid,
                (int) $log->courseid,
                $log->checkid,
                (int) $log->findingid
            );
        }
        return $result;
    }

    /**
     * Load a finding and its check, and make sure it can be fixed.
     *
     * @param int $findingid
     * @return array [finding, base_check]
     */
    private static function load_fixable(int $findingid): array {
        $finding = finding_store::get($findingid);
        $check = check_registry::get_check($finding->checkid);
        if (!$check) {
            throw new \moodle_exception('error:checknotfound', 'local_completiondoctor', '', $finding->checkid);
        }
        if (!$check->can_fix($finding)) {
            throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
        }
        return [$finding, $check];
    }

    /**
     * Write a fix event to Moodle's log.
     *
     * @param string $class Event class.
     * @param int $fixlogid
     * @param int $courseid
     * @param string $checkid
     * @param int $findingid
     */
    private static function log_event(string $class, int $fixlogid, int $courseid, string $checkid, int $findingid): void {
        $context = $courseid === (int) SITEID ? \context_system::instance() : \context_course::instance($courseid);
        $class::create(['context' => $context, 'objectid' => $fixlogid,
            'other' => ['checkid' => $checkid, 'findingid' => $findingid]])->trigger();
    }

    /**
     * Refresh caches after a course was changed.
     *
     * @param int $courseid
     */
    private static function after_change(int $courseid): void {
        if ($courseid !== (int) SITEID) {
            rebuild_course_cache($courseid, true);
        }
        finding_store::invalidate_summary($courseid);
    }

    /**
     * Load the core libraries fixes use (grades, completion criteria). Audits load them through
     * audit_context; fixes can run from web services and the CLI without an audit.
     */
    private static function load_libraries(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
    }
}
