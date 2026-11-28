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
use local_chronifycompliance\security\vulnerability_list;

/**
 * This Moodle release is missing fixes for known security issues, from the list of Moodle security
 * announcements shipped with the plugin (data/vulnerabilities.json). Also reports when the list itself is old.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class corevulnerabilities extends security_check {
    /** @var int Days after which the shipped list counts as old. */
    public const LIST_MAX_AGE = 180;
    /** @var int Advisory ids named in the message. */
    public const NAMED = 3;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'corevulnerabilities';
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
        $list = vulnerability_list::load();
        $findings = [];
        $url = new \moodle_url('/admin/index.php');
        $release = (string) $CFG->release;
        $affecting = vulnerability_list::affecting($release, $list['advisories']);
        if ($affecting) {
            $serious = count(array_filter($affecting, fn($a) => $a['severity'] === 'serious'));
            $fixedin = array_filter(array_column($affecting, 'fixedin'));
            usort($fixedin, fn($a, $b) => vulnerability_list::compare(
                vulnerability_list::parse_version($a),
                vulnerability_list::parse_version($b)
            ));
            $target = end($fixedin) ?: null;
            $params = [
                'release' => $release,
                'count' => count($affecting),
                'serious' => $serious,
                'ids' => implode(', ', array_slice(array_column($affecting, 'id'), 0, self::NAMED)),
                'target' => (string) $target,
            ];
            $findings[] = $this->make_finding(
                (int) SITEID,
                $target ? 'check:corevulnerabilities:message' : 'check:corevulnerabilities:messageunsupported',
                $params,
                ['url' => $url->out(false), 'advisories' => array_column($affecting, 'id')],
                null,
                ['case' => 'missing'],
                $serious ? self::SEVERITY_CRITICAL : null
            );
        }
        $generated = strtotime($list['generated'] . ' 00:00:00 UTC');
        $age = $generated ? (int) floor((time() - $generated) / DAYSECS) : 0;
        if ($age > self::LIST_MAX_AGE) {
            $findings[] = $this->security_finding(
                'check:corevulnerabilities:messagelist',
                ['date' => $list['generated'], 'days' => $age],
                new \moodle_url('/admin/plugins.php'),
                ['case' => 'list'],
                self::SEVERITY_INFO
            );
        }
        return $findings;
    }
}
