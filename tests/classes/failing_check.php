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

namespace local_chronifycompliance\tests;

use local_chronifycompliance\check\audit_context;

/**
 * Check that always throws, used to test error isolation.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_check extends fake_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'failingcheck';
    }

    /**
     * Always fail.
     *
     * @param audit_context $context
     * @return array
     */
    public function run(audit_context $context): array {
        throw new \moodle_exception('error:notfixable', 'local_chronifycompliance');
    }
}
