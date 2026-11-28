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
 * Caches that are off make every page slower: language strings and templates. One click fix turns each back on.
 * Large sites that keep the application cache in files are told about faster stores.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class caching extends site_check {
    use \local_chronifycompliance\check\setting_fix;

    /** @var int Users active in the last 30 days from which the site counts as large. */
    public const LARGE_SITE = 1000;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'caching';
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
        foreach (['langstringcache' => 'langcache', 'cachetemplates' => 'templatecache'] as $setting => $case) {
            if (isset($CFG->$setting) && empty($CFG->$setting)) {
                if (isset($CFG->config_php_settings[$setting])) {
                    $findings[] = $this->security_finding(
                        'check:caching:message' . $case . 'config',
                        [],
                        self::settings($case === 'langcache' ? 'langsettings' : 'templates'),
                        ['case' => $case]
                    );
                } else {
                    $findings[] = $this->setting_finding(
                        'check:caching:message' . $case,
                        [],
                        self::settings($case === 'langcache' ? 'langsettings' : 'templates'),
                        $setting,
                        null,
                        '1',
                        'fix:setting:' . $setting,
                        ['case' => $case]
                    );
                }
            }
        }
        // Large sites: the application cache in plain files is slow; Redis or Memcached are made for it.
        $users = $DB->count_records_select('user', 'deleted = 0 AND lastaccess > :since', ['since' => time() - 30 * DAYSECS]);
        if ($users >= self::LARGE_SITE) {
            $config = \cache_config::instance();
            $store = $config->get_mode_mappings()[0]['store'] ?? 'default_application';
            $plugin = $config->get_all_stores()[$store]['plugin'] ?? 'file';
            if ($plugin === 'file') {
                $findings[] = $this->security_finding(
                    'check:caching:messagefilestore',
                    ['users' => $users],
                    new \moodle_url('/cache/admin.php'),
                    ['case' => 'store'],
                    self::SEVERITY_INFO
                );
            }
        }
        return $findings;
    }
}
