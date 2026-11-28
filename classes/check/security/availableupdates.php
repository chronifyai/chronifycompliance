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

namespace local_chronifycompliance\check\security;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\security_check;

/**
 * Updates that Moodle's own update check found: a newer build of this Moodle version, and plugin updates. Also
 * reports when Moodle does not check for updates. Reads what Moodle already fetched; makes no web request.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availableupdates extends security_check {
    /** @var int Days after which Moodle's last update check counts as old. */
    public const CHECK_MAX_AGE = 7;
    /** @var int Plugins named in the message. */
    public const NAMED = 5;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'availableupdates';
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
        global $CFG;
        $checker = \core\update\checker::instance();
        $notifications = new \moodle_url('/admin/index.php');
        if (!$checker->enabled() || empty($CFG->updateautocheck)) {
            return [$this->security_finding(
                'check:availableupdates:messageoff',
                [],
                self::settings('updatenotifications'),
                ['case' => 'off'],
                self::SEVERITY_INFO
            )];
        }
        $findings = [];
        $last = (int) $checker->get_last_timefetched();
        if ($last < time() - self::CHECK_MAX_AGE * DAYSECS) {
            $findings[] = $this->security_finding(
                $last ? 'check:availableupdates:messagestale' : 'check:availableupdates:messagenever',
                ['date' => $last ? ['date' => $last] : ''],
                $notifications,
                ['case' => 'stale'],
                self::SEVERITY_INFO
            );
        }

        // Newer builds of the same Moodle version carry the security fixes of that version.
        $branch = moodle_major_version();
        $core = array_filter(
            $checker->get_update_info('core', ['minmaturity' => MATURITY_STABLE, 'notifybuilds' => true]) ?? [],
            fn($info) => isset($info->release) && str_starts_with((string) $info->release, $branch . '.')
        );
        if ($core) {
            $newest = end($core);
            $findings[] = $this->security_finding(
                'check:availableupdates:messagecore',
                ['release' => $newest->release],
                $notifications,
                ['case' => 'core']
            );
        }

        $plugins = [];
        foreach (\core_plugin_manager::instance()->get_plugins() as $type => $list) {
            foreach ($list as $plugin) {
                if (!$plugin->is_standard() && $plugin->available_updates()) {
                    $plugins[] = $plugin->displayname;
                }
            }
        }
        if ($plugins) {
            sort($plugins);
            $findings[] = $this->security_finding('check:availableupdates:messageplugins', ['count' => count($plugins),
                'names' => implode(', ', array_slice($plugins, 0, self::NAMED))], new \moodle_url(
                    '/admin/plugins.php',
                    ['updatesonly' => 1]
                ), ['case' => 'plugins'], self::SEVERITY_INFO);
        }
        return $findings;
    }
}
