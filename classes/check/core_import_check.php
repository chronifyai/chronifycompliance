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

use core\check\result;

/**
 * Reads the results of Moodle's own Check API (Site administration > Reports > Security checks, Performance
 * overview, System status) and stores each result that is not OK as a finding, with its link to the setting.
 *
 * Checks of this plugin are skipped (they are already checks here). Checks that call the site over HTTP or that
 * take long are skipped too (EXCLUDED), so the site audit stays fast and makes no web requests.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class core_import_check extends site_check {
    /** @var string[] Core check refs that are not read: publicpaths fetches the site's own URLs, dbschema is slow. */
    public const EXCLUDED = ['core_publicpaths', 'core_dbschema'];
    /** @var int[] Core status => our severity; OK and N/A are not findings. */
    public const SEVERITIES = [
        result::CRITICAL => self::SEVERITY_CRITICAL,
        result::ERROR => self::SEVERITY_CRITICAL,
        result::WARNING => self::SEVERITY_WARNING,
        result::INFO => self::SEVERITY_INFO,
        result::UNKNOWN => self::SEVERITY_INFO,
    ];

    /**
     * Check API types read by this check.
     *
     * @return string[] Of 'security', 'performance', 'status'.
     */
    abstract protected function get_types(): array;

    /**
     * Core checks that a check of this plugin covers better, so they are not read twice.
     *
     * @return string[] Core check refs.
     */
    protected function get_covered(): array {
        return [];
    }

    /**
     * Severity of a check whose results each carry their own severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
    }

    /**
     * Core checks of the types, without this plugin's checks and the excluded ones.
     *
     * @return \core\check\check[]
     */
    public function get_core_checks(): array {
        $checks = [];
        foreach ($this->get_types() as $type) {
            foreach (\core\check\manager::get_checks($type) as $check) {
                $skip = array_merge(self::EXCLUDED, $this->get_covered());
                if ($check->get_component() === 'local_chronifycompliance' || in_array($check->get_ref(), $skip, true)) {
                    continue;
                }
                $checks[$check->get_ref()] = $check;
            }
        }
        return $checks;
    }

    /**
     * One finding per core check whose result is not OK.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $findings = [];
        foreach ($this->get_core_checks() as $ref => $check) {
            try {
                $result = $check->get_result();
            } catch (\Throwable $e) {
                continue;
            }
            $severity = self::SEVERITIES[$result->get_status()] ?? null;
            if ($severity === null) {
                continue;
            }
            $link = $check->get_action_link();
            $url = $link ? $link->url->out(false) : null;
            $findings[] = $this->make_finding(
                (int) SITEID,
                'check:' . $this->get_id() . ':message',
                [
                    'name' => self::plain($check->get_name()),
                    'summary' => self::plain($result->get_summary()),
                ],
                ['ref' => $ref, 'status' => $result->get_status(), 'url' => $url],
                null,
                ['ref' => $ref],
                $severity
            );
        }
        return $findings;
    }

    /**
     * Plain text of a core summary, which is HTML.
     *
     * @param string $html
     * @return string
     */
    private static function plain(string $html): string {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
