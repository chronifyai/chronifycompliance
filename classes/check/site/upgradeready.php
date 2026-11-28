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

namespace local_chronifycompliance\check\site;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\check\site_check;

/**
 * Plugins block the next upgrade: plugins waiting for their upgrade to run or missing from the disk (Warning),
 * and add-on plugins that do not declare support for the next Moodle version (Info).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgradeready extends site_check {
    /** @var int[] Moodle branches in release order. */
    public const BRANCHES = [405, 500, 501, 502, 503];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'upgradeready';
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
        global $CFG, $DB;
        $findings = [];
        $pending = [];
        $notready = [];
        $next = self::next_branch((int) moodle_major_version(true));
        foreach (\core_plugin_manager::instance()->get_plugins() as $type => $plugins) {
            foreach ($plugins as $plugin) {
                $status = $plugin->get_status();
                if (
                    in_array($status, [\core_plugin_manager::PLUGIN_STATUS_UPGRADE, \core_plugin_manager::PLUGIN_STATUS_MISSING,
                        \core_plugin_manager::PLUGIN_STATUS_DOWNGRADE, \core_plugin_manager::PLUGIN_STATUS_NEW], true)
                ) {
                    $pending[] = $plugin->component;
                    continue;
                }
                $supported = $plugin->pluginsupported ?? null;
                if ($next && !$plugin->is_standard() && is_array($supported) && (int) $supported[1] < $next) {
                    $notready[] = $plugin->displayname;
                }
            }
        }
        if ($pending) {
            sort($pending);
            $findings[] = $this->security_finding(
                'check:upgradeready:messagepending',
                ['count' => count($pending),
                'names' => implode(', ', array_slice($pending, 0, 5))],
                new \moodle_url('/admin/index.php'),
                ['case' => 'pending']
            );
        }
        if ($notready) {
            sort($notready);
            $findings[] = $this->security_finding(
                'check:upgradeready:messagenext',
                ['count' => count($notready),
                'names' => implode(', ', array_slice($notready, 0, 5)), 'next' => self::branch_name($next)],
                new \moodle_url('/admin/plugins.php', ['contribonly' => 1]),
                ['case' => 'next'],
                self::SEVERITY_INFO
            );
        }
        return $findings;
    }

    /**
     * The branch after this one, or null when it is not known yet.
     *
     * @param int $branch For example 405.
     * @return int|null
     */
    public static function next_branch(int $branch): ?int {
        $index = array_search($branch, self::BRANCHES, true);
        return $index !== false && isset(self::BRANCHES[$index + 1]) ? self::BRANCHES[$index + 1] : null;
    }

    /**
     * Branch as people write it: 500 is "5.0".
     *
     * @param int $branch
     * @return string
     */
    public static function branch_name(int $branch): string {
        return intdiv($branch, 100) . '.' . ($branch % 100);
    }
}
