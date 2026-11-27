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

/**
 * Grants the plugin's capabilities to the standard roles in tests.
 *
 * The capabilities ship with no archetypes, so a test that acts as a teacher or a manager grants
 * them first, the way a site would.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_grants {
    /** @var array<string, string[]> Role shortnames by capability. */
    private const GRANTS = [
        'local/completiondoctor:viewcoursereport' => ['editingteacher', 'manager'],
        'local/completiondoctor:viewlearnerdiagnostic' => ['editingteacher', 'teacher', 'manager'],
        'local/completiondoctor:ignorefinding' => ['editingteacher', 'manager'],
        'local/completiondoctor:applyfix' => ['manager'],
        'local/completiondoctor:viewsitereport' => ['manager'],
        'local/completiondoctor:checklinks' => ['editingteacher', 'manager'],
        'local/completiondoctor:runscan' => ['manager'],
    ];

    /**
     * Allow the capabilities at system level for the roles that used to get them by default.
     */
    public static function grant(): void {
        global $DB;
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $systemid = \context_system::instance()->id;
        foreach (self::GRANTS as $capability => $roles) {
            foreach ($roles as $role) {
                assign_capability($capability, CAP_ALLOW, $roleids[$role], $systemid, true);
            }
        }
    }
}
