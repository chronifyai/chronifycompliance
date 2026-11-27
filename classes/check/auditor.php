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

namespace local_chronifycompliance\check;

use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;

/**
 * Shared logic of the course and site auditors.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class auditor {
    /** @var int Seconds to wait for the lock of a course. */
    protected const LOCK_TIMEOUT = 10;

    /**
     * Run checks against a context and optionally store the findings.
     *
     * @param audit_context $context
     * @param base_check[] $checks Checks to run.
     * @param bool $save Whether to store the findings.
     * @return audit_result
     */
    protected static function run_checks(audit_context $context, array $checks, bool $save): audit_result {
        global $DB;
        $courseid = (int) $context->course->id;
        $result = new audit_result($courseid);
        $start = microtime(true);
        $queries = $DB->perf_get_queries();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        foreach ($checks as $check) {
            if (!$check->applies_to_course($context->course)) {
                $result->checksrun[] = $check->get_id();
                continue;
            }
            try {
                $findings = $check->run($context);
                foreach ($findings as $finding) {
                    if (
                        !$finding instanceof finding || $finding->checkid !== $check->get_id()
                            || $finding->courseid !== $courseid
                    ) {
                        throw new \coding_exception('Invalid finding returned');
                    }
                    $result->findings[] = $finding;
                }
                $result->checksrun[] = $check->get_id();
            } catch (\Throwable $e) {
                // One broken check must not stop the audit; its findings are left untouched.
                $result->errors[$check->get_id()] = $e->getMessage();
                $result->findings = array_values(array_filter(
                    $result->findings,
                    fn(finding $f): bool => $f->checkid !== $check->get_id()
                ));
                debugging('Chronify.Compliance check ' . $check->get_id() . ' failed on course ' . $courseid . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $result->duration = microtime(true) - $start;
        $result->queries = $DB->perf_get_queries() - $queries;
        if ($save) {
            self::save_result($result);
        }
        $result->peakmemory = memory_get_peak_usage();
        return $result;
    }

    /**
     * Store the findings and the audit record of a result.
     *
     * @param audit_result $result
     */
    protected static function save_result(audit_result $result): void {
        $result->stats = finding_store::sync($result->courseid, $result->findings, $result->checksrun);
        audit_store::record($result, $result->timestarted);
    }

    /**
     * Filter checks by id.
     *
     * @param base_check[] $checks
     * @param string[]|null $onlyids Only these ids, null for all.
     * @return base_check[]
     */
    protected static function filter_checks(array $checks, ?array $onlyids): array {
        if ($onlyids === null) {
            return $checks;
        }
        return array_intersect_key($checks, array_flip($onlyids));
    }

    /**
     * Acquire the lock for an audit target.
     *
     * @param string $resource Lock resource name.
     * @return \core\lock\lock
     * @throws \moodle_exception When the lock cannot be acquired.
     */
    protected static function get_lock(string $resource): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_chronifycompliance');
        $lock = $factory->get_lock($resource, static::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('error:auditlocked', 'local_chronifycompliance', '', $resource);
        }
        return $lock;
    }
}
