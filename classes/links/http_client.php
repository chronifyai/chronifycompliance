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

namespace local_completiondoctor\links;

/**
 * Makes the requests of the link check through the core curl class, so the site's blocked hosts
 * and ports, proxy and plugin security helpers apply. Tests replace this class.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client {
    /** @var string User agent sent with every request. */
    public const USER_AGENT = 'Mozilla/5.0 (compatible; MoodleLinkCheck/1.0)';
    /** @var int Largest response body accepted, bytes. */
    public const MAX_BODY_BYTES = 1048576;

    /** @var int Request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor.
     *
     * @param int $timeout Seconds per request.
     */
    public function __construct(int $timeout) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $this->timeout = max(1, $timeout);
    }

    /**
     * Whether a host name exists in DNS. IP addresses always exist.
     *
     * @param string $host
     * @return bool
     */
    public function host_exists(string $host): bool {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return true;
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records) {
            return true;
        }
        return (bool) @gethostbynamel($host);
    }

    /**
     * Request a URL, following redirects.
     *
     * @param string $url
     * @param bool $head HEAD request; otherwise a GET of the first bytes.
     * @return array Keys code (HTTP status, 0 when none), errno (curl error) and blocked (site security refused it).
     */
    public function request(string $url, bool $head): array {
        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(5, $this->timeout),
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_USERAGENT' => self::USER_AGENT,
            'CURLOPT_MAXFILESIZE' => self::MAX_BODY_BYTES,
        ];
        if ($head) {
            $curl->head($url, $options);
        } else {
            $curl->get($url, [], $options + ['CURLOPT_RANGE' => '0-1023']);
        }
        $info = (array) $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        $errno = (int) $curl->get_errno();
        return [
            'code' => $code,
            'errno' => $errno,
            'blocked' => $code === 0 && $errno === 0 && (string) $curl->error !== '',
        ];
    }

    /**
     * Wait before the next request to the same host.
     *
     * @param int $microseconds
     */
    public function wait(int $microseconds): void {
        usleep($microseconds);
    }
}
