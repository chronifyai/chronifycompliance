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
 * The PHP or database version no longer gets security updates from its makers, or stops getting them within 90
 * days. The end dates are kept in this check and updated with the plugin.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phpdbsupport extends site_check {
    /** @var string[] End of security support of PHP versions (php.net). */
    public const PHP_END = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31',
        '8.5' => '2029-12-31'];
    /** @var array End of support of database versions, by vendor and branch (vendors' published policies). */
    public const DB_END = [
        'postgres' => ['13' => '2025-11-13', '14' => '2026-11-12', '15' => '2027-11-11', '16' => '2028-11-09',
            '17' => '2029-11-08', '18' => '2030-11-14'],
        'mysql' => ['8.0' => '2026-04-30', '8.4' => '2032-04-30'],
        'mariadb' => ['10.6' => '2026-07-06', '10.11' => '2028-02-16', '11.4' => '2029-05-29', '11.8' => '2030-06-04'],
    ];
    /** @var array|null Versions to judge instead of the real ones (keys php, vendor, version), for tests and demos. */
    public static ?array $versions = null;
    /** @var int Days before the end of support from which it is reported. */
    public const WARN_DAYS = 90;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'phpdbsupport';
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
        $php = self::$versions['php'] ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ($finding = $this->support_finding('php', 'PHP', $php, self::PHP_END[$php] ?? null)) {
            $findings[] = $finding;
        }
        $family = $DB->get_dbfamily();
        $info = $DB->get_server_info();
        $version = (string) ($info['version'] ?? '');
        $vendor = $family === 'mysql' && stripos($info['description'] ?? '', 'mariadb') !== false ? 'mariadb' : $family;
        if ($vendor === 'mariadb' && preg_match('/(\d+\.\d+)\.\d+-MariaDB/i', $info['description'] ?? '', $m)) {
            $version = $m[1];
        }
        if (self::$versions !== null) {
            $vendor = self::$versions['vendor'];
            $version = self::$versions['version'];
        }
        $branch = self::branch($vendor, $version);
        $names = ['postgres' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'];
        if (
            isset(self::DB_END[$vendor]) && ($finding = $this->support_finding(
                'db',
                $names[$vendor],
                $branch,
                self::DB_END[$vendor][$branch] ?? null
            ))
        ) {
            $findings[] = $finding;
        }
        return $findings;
    }

    /**
     * Major version a database end date is listed by: PostgreSQL "16", MySQL and MariaDB "8.0".
     *
     * @param string $vendor
     * @param string $version
     * @return string
     */
    public static function branch(string $vendor, string $version): string {
        $parts = explode('.', $version);
        return $vendor === 'postgres' ? (string) (int) $parts[0] : (int) $parts[0] . '.' . (int) ($parts[1] ?? 0);
    }

    /**
     * A finding when support has ended or ends within WARN_DAYS.
     *
     * @param string $case php or db.
     * @param string $name
     * @param string $version
     * @param string|null $end Y-m-d, or null when unknown (not reported).
     * @param int|null $now
     * @return finding|null
     */
    public function support_finding(string $case, string $name, string $version, ?string $end, ?int $now = null): ?finding {
        if ($end === null) {
            return null;
        }
        $now = $now ?? time();
        $endtime = strtotime($end . ' 23:59:59 UTC');
        $url = new \moodle_url('/admin/environment.php');
        $params = ['name' => $name, 'version' => $version, 'date' => ['date' => $endtime]];
        if ($endtime < $now) {
            return $this->security_finding('check:phpdbsupport:messageended', $params, $url, ['case' => $case]);
        }
        if ($endtime < $now + self::WARN_DAYS * DAYSECS) {
            return $this->security_finding(
                'check:phpdbsupport:messageending',
                $params,
                $url,
                ['case' => $case],
                self::SEVERITY_INFO
            );
        }
        return null;
    }
}
