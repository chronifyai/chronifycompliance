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
 * Course completion is enabled but no completion criteria are set, so nobody can complete the course.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nocriteria extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'nocriteria';
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
     * Run the check. Critical when activities track completion (completion is clearly intended), info otherwise.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        if ($context->get_criteria()) {
            return [];
        }
        $count = count($this->get_tracked_cms($context));
        if ($count) {
            return [$this->make_finding((int) $context->course->id, 'check:nocriteria:message', ['count' => $count])];
        }
        return [$this->make_finding(
            (int) $context->course->id,
            'check:nocriteria:messagenoactivities',
            [],
            [],
            null,
            [],
            self::SEVERITY_INFO
        )];
    }
}
