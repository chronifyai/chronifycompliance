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
 * Files that should never be inside the web folder: the data folder (moodledata), and copies of config.php that
 * the web server would send as plain text. Checked on disk; no web requests are made.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exposedfiles extends security_check {
    /** @var string[] File name patterns of config.php copies in the Moodle folder. */
    public const CONFIG_COPIES = [
        'config.php?*', 'config.php.*', 'config.*.php', 'config_*.php', 'config.old', 'config.bak', '*.php~',
    ];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'exposedfiles';
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
        global $CFG, $DB;
        $findings = [];
        $dirroot = rtrim(str_replace('\\', '/', realpath($CFG->dirroot) ?: $CFG->dirroot), '/') . '/';
        $dataroot = rtrim(str_replace('\\', '/', realpath($CFG->dataroot) ?: $CFG->dataroot), '/') . '/';
        if (str_starts_with($dataroot, $dirroot)) {
            $findings[] = $this->security_finding(
                'check:exposedfiles:messagedataroot',
                [],
                new \moodle_url('/admin/environment.php'),
                ['case' => 'dataroot']
            );
        }
        $copies = [];
        foreach (self::CONFIG_COPIES as $pattern) {
            foreach (glob($CFG->dirroot . '/' . $pattern) ?: [] as $file) {
                if (is_file($file) && !in_array(basename($file), ['config.php', 'config-dist.php'], true)) {
                    $copies[] = basename($file);
                }
            }
        }
        if ($copies) {
            sort($copies);
            $findings[] = $this->security_finding(
                'check:exposedfiles:messageconfig',
                ['files' => implode(', ', array_unique($copies))],
                new \moodle_url('/admin/environment.php'),
                ['case' => 'config']
            );
        }
        return $findings;
    }
}
