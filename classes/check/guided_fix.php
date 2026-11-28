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

/**
 * A check whose fix is a short wizard (guided.php) instead of one click: the admin answers a few questions step by
 * step, reviews the exact changes and applies them. The answers are the fix parameters (get_fix_parameters()),
 * so the change goes through the fixer like any other fix: one transaction, one fix log entry, undo.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface guided_fix {
    /**
     * Steps of the wizard before the review step.
     *
     * @param finding $finding
     * @return array[] Each with keys key, title and intro (language strings of this plugin) and fields (parameter
     *                 names from get_fix_parameters()).
     */
    public function get_guided_steps(finding $finding): array;
}
