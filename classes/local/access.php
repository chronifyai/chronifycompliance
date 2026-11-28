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

namespace local_chronifycompliance\local;

use local_chronifycompliance\check\finding;

/**
 * Permission helpers shared by pages and web services.
 *
 * Course findings are checked in the course context; site findings (course id = SITEID) in the
 * system context with the site report capability.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Context a finding belongs to.
     *
     * @param finding $finding
     * @return \context
     */
    public static function get_finding_context(finding $finding): \context {
        if ($finding->courseid === (int) SITEID) {
            return \context_system::instance();
        }
        return \context_course::instance($finding->courseid);
    }

    /**
     * Require a capability on a finding.
     *
     * @param finding $finding
     * @param string $capability Short name, for example "applyfix".
     * @return \context
     */
    public static function require_finding_capability(finding $finding, string $capability): \context {
        $context = self::get_finding_context($finding);
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            require_capability('local/chronifycompliance:viewsitereport', $context);
            if ($capability === 'applyfix' || $capability === 'ignorefinding') {
                require_capability('moodle/site:config', $context);
            }
            return $context;
        }
        require_capability('local/chronifycompliance:' . $capability, $context);
        return $context;
    }
}
