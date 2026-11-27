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

/**
 * Upgrade steps for Completion Doctor.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion Version being upgraded from.
 * @return bool
 */
function xmldb_local_completiondoctor_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026110500) {
        // The capabilities no longer have archetypes: take them away from every role, defaults and
        // overrides alike, so no role keeps the access the earlier install granted.
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT roleid, capability
               FROM {role_capabilities}
              WHERE " . $DB->sql_like('capability', ':capability'),
            ['capability' => 'local/completiondoctor:%']
        );
        $grants = iterator_to_array($rs, false);
        $rs->close();
        foreach ($grants as $grant) {
            unassign_capability($grant->capability, $grant->roleid, null, false);
        }

        upgrade_plugin_savepoint(true, 2026110500, 'local', 'completiondoctor');
    }

    return true;
}
