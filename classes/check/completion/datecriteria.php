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

namespace local_chronifycompliance\check\completion;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_check;
use local_chronifycompliance\check\finding;

/**
 * Date and duration completion conditions that cannot work as intended: "days after enrolment" longer
 * than every enrolment lasts (critical: enrolments end first), or a completion date after the
 * course end date (warning: learners complete only after the course is over).
 *
 * The enrolment period of manual enrolment is only the default of the enrol dialog (sync, web
 * services and teachers set their own end), so a manual method counts as unlimited.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class datecriteria extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'datecriteria';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $course = $context->course;
        $findings = [];

        foreach ($context->get_criteria(COMPLETION_CRITERIA_TYPE_DURATION) as $criterion) {
            $needed = (int) $criterion->enrolperiod;
            $methods = array_filter(
                enrolment::get_methods($context),
                fn($m) => (int) $m->status === ENROL_INSTANCE_ENABLED && $m->enrol !== 'guest'
            );
            // Only when every method limits the enrolment: unlimited methods let learners stay long enough.
            if (!$needed || !$methods || array_filter($methods, fn($m) => empty($m->enrolperiod) || $m->enrol === 'manual')) {
                continue;
            }
            $longest = max(array_map(fn($m) => (int) $m->enrolperiod, $methods));
            if ($longest < $needed) {
                $findings[] = $this->make_finding(
                    (int) $course->id,
                    'check:datecriteria:messageduration',
                    ['needed' => (int) round($needed / DAYSECS), 'longest' => (int) round($longest / DAYSECS)],
                    ['criterionid' => (int) $criterion->id],
                    null,
                    ['criterionid' => (int) $criterion->id]
                );
            }
        }

        foreach ($context->get_criteria(COMPLETION_CRITERIA_TYPE_DATE) as $criterion) {
            $date = (int) $criterion->timeend;
            if (!empty($course->enddate) && $date > (int) $course->enddate) {
                $findings[] = $this->make_finding(
                    (int) $course->id,
                    'check:datecriteria:messageafterend',
                    ['date' => ['date' => $date], 'enddate' => ['date' => (int) $course->enddate]],
                    ['criterionid' => (int) $criterion->id],
                    null,
                    ['criterionid' => (int) $criterion->id],
                    self::SEVERITY_WARNING
                );
            }
        }
        return $findings;
    }
}
