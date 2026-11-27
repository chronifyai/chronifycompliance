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

namespace local_completiondoctor\check\course;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\course_check;
use local_completiondoctor\check\finding;

/**
 * A course with completion conditions that new learners cannot join: no enabled enrolment method
 * (guest access does not count, guests are never tracked), or every enabled method has passed its
 * end date. Courses that have ended are skipped.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'enrolment';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $course = $context->course;
        $now = time();
        if (!$context->get_criteria() || (!empty($course->enddate) && (int) $course->enddate <= $now)) {
            return [];
        }
        $methods = array_filter(
            self::get_methods($context),
            fn($m) => (int) $m->status === ENROL_INSTANCE_ENABLED && $m->enrol !== 'guest'
        );
        if (!$methods) {
            return [$this->make_finding(
                (int) $course->id,
                'check:enrolment:messagenomethod',
                [],
                [],
                null,
                ['case' => 'nomethod']
            )];
        }
        $open = array_filter($methods, fn($m) => empty($m->enrolenddate) || (int) $m->enrolenddate > $now);
        if (!$open) {
            $last = max(array_map(fn($m) => (int) $m->enrolenddate, $methods));
            return [$this->make_finding(
                (int) $course->id,
                'check:enrolment:messageclosed',
                ['date' => ['date' => $last]],
                [],
                null,
                ['case' => 'closed']
            )];
        }
        return [];
    }

    /**
     * Enrolment methods of the course (the enrol table is small and indexed by course).
     *
     * @param audit_context $context
     * @return \stdClass[]
     */
    public static function get_methods(audit_context $context): array {
        return $context->remember('enrolmethods', function (audit_context $context): array {
            global $DB;
            return $DB->get_records(
                'enrol',
                ['courseid' => $context->course->id],
                'id',
                'id, enrol, status, enrolperiod, enrolenddate'
            );
        });
    }
}
