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

namespace local_completiondoctor\tests;

use local_completiondoctor\check\audit_context;

/**
 * Site-scope check reporting one finding, used by the site auditor tests.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_site_check extends fake_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'fakesitecheck';
    }

    /**
     * Scope.
     *
     * @return string
     */
    public function get_scope(): string {
        return self::SCOPE_SITE;
    }

    /**
     * One finding about the site.
     *
     * @param audit_context $context
     * @return array
     */
    public function run(audit_context $context): array {
        return [$this->emit((int) $context->course->id, 'site')];
    }
}
