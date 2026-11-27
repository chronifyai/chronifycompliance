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

namespace local_chronifycompliance\links;

/**
 * Decides whether one link works.
 *
 * Links to this site are checked in the database: the activity, course or file must exist.
 * Other links are requested over HTTP: first a DNS lookup (so a mistyped domain is reported
 * as not existing), then HEAD, then a short GET when the server refuses HEAD. Requests to the
 * same host are at least one second apart, and recent results of the same URL are reused.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_checker {
    /** @var int The link works. */
    public const STATUS_OK = 0;
    /** @var int The link is broken. */
    public const STATUS_BROKEN = 1;
    /** @var int The link could not be verified (access refused, timeout, server error). */
    public const STATUS_UNVERIFIED = 2;
    /** @var int The link was not checked (blocked by the site security settings, or limits reached). */
    public const STATUS_SKIPPED = 3;
    /** @var string Link to this site. */
    public const KIND_INTERNAL = 'internal';
    /** @var string Link to another site. */
    public const KIND_EXTERNAL = 'external';
    /** @var int Microseconds between two requests to the same host. */
    public const HOST_GAP = 1000000;
    /** @var int[] HTTP codes after which a HEAD request is repeated as GET. */
    private const RETRY_WITH_GET = [400, 403, 404, 405, 406, 410, 429, 500, 501, 503];

    /** @var http_client */
    private http_client $http;
    /** @var int Maximum external URLs requested in this run. */
    private int $maxexternal;
    /** @var int Seconds a stored result of the same URL is reused. */
    private int $cachesecs;
    /** @var int Unix time after which no more requests are made. */
    private int $deadline;
    /** @var int External URLs requested so far. */
    private int $requested = 0;
    /** @var float[] Time of the last request per host. */
    private array $lastrequest = [];
    /** @var bool[] DNS results per host. */
    private array $hosts = [];

    /**
     * Constructor.
     *
     * @param http_client $http
     * @param int $maxexternal Maximum external URLs requested.
     * @param int $cachesecs Seconds a stored result is reused.
     * @param int $timebudget Seconds after which remaining links are skipped.
     */
    public function __construct(http_client $http, int $maxexternal, int $cachesecs, int $timebudget) {
        $this->http = $http;
        $this->maxexternal = $maxexternal;
        $this->cachesecs = $cachesecs;
        $this->deadline = time() + $timebudget;
    }

    /**
     * Create a checker with the plugin settings.
     *
     * @return self
     */
    public static function from_config(): self {
        $config = get_config('local_chronifycompliance');
        $timeout = (int) ($config->linktimeout ?? 0) ?: 8;
        $maxurls = (int) ($config->linkmaxurls ?? 0) ?: 500;
        $cachehours = isset($config->linkcachehours) && $config->linkcachehours !== '' ? (int) $config->linkcachehours : 24;
        return new self(new http_client($timeout), $maxurls, $cachehours * HOURSECS, 15 * MINSECS);
    }

    /**
     * Check one link.
     *
     * @param string $url
     * @param array|null $area File area of the text the link is in, for @@PLUGINFILE@@ links:
     *     contextid, component, filearea, itemid.
     * @return array Keys kind, status, httpcode, reason (lang key suffix or null) and timechecked.
     */
    public function check(string $url, ?array $area = null): array {
        global $CFG;
        if (str_starts_with($url, '@@PLUGINFILE@@/')) {
            return $this->check_embedded($url, $area);
        }
        $site = parse_url($CFG->wwwroot);
        if (str_starts_with($url, '/')) {
            $url = $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return self::result(self::KIND_EXTERNAL, self::STATUS_BROKEN, 0, 'invalid');
        }
        $sitepath = rtrim($site['path'] ?? '', '/');
        $path = $parts['path'] ?? '/';
        if (
            strcasecmp($parts['host'], $site['host']) === 0 && ($parts['port'] ?? null) === ($site['port'] ?? null)
                && ($sitepath === '' || $path === $sitepath || str_starts_with($path, $sitepath . '/'))
        ) {
            return $this->check_internal(substr($path, strlen($sitepath)) ?: '/', $parts['query'] ?? '');
        }
        return $this->check_external($url, strtolower($parts['host']));
    }

    /**
     * Build a result.
     *
     * @param string $kind
     * @param int $status
     * @param int $httpcode
     * @param string|null $reason
     * @param int|null $timechecked When the result was found; now by default.
     * @return array
     */
    private static function result(
        string $kind,
        int $status,
        int $httpcode = 0,
        ?string $reason = null,
        ?int $timechecked = null
    ): array {
        return ['kind' => $kind, 'status' => $status, 'httpcode' => $httpcode, 'reason' => $reason,
            'timechecked' => $timechecked ?? time()];
    }

    /**
     * A file embedded in a text: it must exist in the text's file area.
     *
     * @param string $url
     * @param array|null $area
     * @return array
     */
    private function check_embedded(string $url, ?array $area): array {
        global $DB;
        if (!$area) {
            return self::result(self::KIND_INTERNAL, self::STATUS_SKIPPED);
        }
        $relative = rawurldecode((string) strtok(substr($url, strlen('@@PLUGINFILE@@')), '?'));
        $filename = basename($relative);
        $filepath = rtrim(dirname($relative), '/') . '/';
        $exists = $DB->record_exists('files', [
            'contextid' => $area['contextid'],
            'component' => $area['component'],
            'filearea' => $area['filearea'],
            'itemid' => $area['itemid'],
            'filepath' => $filepath === '//' ? '/' : $filepath,
            'filename' => $filename,
        ]);
        return $exists ? self::result(self::KIND_INTERNAL, self::STATUS_OK)
            : self::result(self::KIND_INTERNAL, self::STATUS_BROKEN, 0, 'filemissing');
    }

    /**
     * A link to this site: activities, courses and files must exist.
     *
     * @param string $path Path relative to the site root.
     * @param string $query
     * @return array
     */
    private function check_internal(string $path, string $query): array {
        global $DB;
        parse_str($query, $params);
        $id = isset($params['id']) && is_scalar($params['id']) ? (int) $params['id'] : 0;
        if (preg_match('~^/mod/[a-z0-9_]+/view\.php$~', $path) && $id) {
            $exists = $DB->record_exists('course_modules', ['id' => $id, 'deletioninprogress' => 0]);
            return self::result(
                self::KIND_INTERNAL,
                $exists ? self::STATUS_OK : self::STATUS_BROKEN,
                0,
                $exists ? null : 'cmdeleted'
            );
        }
        if (in_array($path, ['/course/view.php', '/course/info.php'], true) && $id) {
            $exists = $DB->record_exists('course', ['id' => $id]);
            return self::result(
                self::KIND_INTERNAL,
                $exists ? self::STATUS_OK : self::STATUS_BROKEN,
                0,
                $exists ? null : 'coursedeleted'
            );
        }
        if (preg_match('~^/draftfile\.php(/|$)~', $path)) {
            return self::result(self::KIND_INTERNAL, self::STATUS_BROKEN, 0, 'draftfile');
        }
        if (preg_match('~^/(?:webservice/|token)?pluginfile\.php(/.*)?$~', $path, $matches)) {
            $file = $matches[1] ?? '';
            if ($file === '' && isset($params['file']) && is_string($params['file'])) {
                $file = $params['file'];
            }
            return $this->check_pluginfile($file);
        }
        return self::result(self::KIND_INTERNAL, self::STATUS_OK);
    }

    /**
     * A pluginfile.php link: the context must exist and hold a file with this name in this area.
     *
     * @param string $file Path after pluginfile.php: /contextid/component/filearea/.../filename.
     * @return array
     */
    private function check_pluginfile(string $file): array {
        global $DB;
        $segments = array_values(array_filter(explode('/', $file), fn($s) => $s !== ''));
        // A tokenpluginfile.php link starts with the token.
        if (isset($segments[1]) && !ctype_digit($segments[0]) && ctype_digit($segments[1])) {
            array_shift($segments);
        }
        if (count($segments) < 4 || !ctype_digit($segments[0])) {
            return self::result(self::KIND_INTERNAL, self::STATUS_OK);
        }
        $exists = $DB->record_exists_select(
            'files',
            'contextid = :contextid AND component = :component AND filearea = :filearea AND filename = :filename',
            ['contextid' => (int) $segments[0], 'component' => $segments[1], 'filearea' => $segments[2],
                'filename' => rawurldecode(end($segments))]
        );
        return $exists ? self::result(self::KIND_INTERNAL, self::STATUS_OK)
            : self::result(self::KIND_INTERNAL, self::STATUS_BROKEN, 0, 'filemissing');
    }

    /**
     * A link to another site.
     *
     * @param string $url
     * @param string $host Lower case host.
     * @return array
     */
    private function check_external(string $url, string $host): array {
        global $CFG;
        if (!preg_match('~^https?://~i', $url)) {
            return self::result(self::KIND_EXTERNAL, self::STATUS_SKIPPED);
        }
        $cached = link_store::get_recent(sha1($url), time() - $this->cachesecs);
        if ($cached) {
            // Keep the original time, so a result is never reused for longer than the cache time.
            return self::result(
                self::KIND_EXTERNAL,
                (int) $cached->status,
                (int) $cached->httpcode,
                $cached->reason,
                (int) $cached->timechecked
            );
        }
        if ($this->requested >= $this->maxexternal || time() >= $this->deadline) {
            return self::result(self::KIND_EXTERNAL, self::STATUS_SKIPPED, 0, 'limit');
        }
        $this->requested++;

        // Behind a proxy the server may not resolve outside names itself, so only the proxy can tell.
        if (empty($CFG->proxyhost)) {
            if (!isset($this->hosts[$host])) {
                $this->hosts[$host] = $this->http->host_exists($host);
            }
            if (!$this->hosts[$host]) {
                return self::result(self::KIND_EXTERNAL, self::STATUS_BROKEN, 0, 'dns');
            }
        }

        $response = $this->request($url, $host, true);
        if (!$response['blocked'] && !$response['errno'] && in_array($response['code'], self::RETRY_WITH_GET, true)) {
            // Many servers answer HEAD wrongly; ask again for the first bytes.
            $response = $this->request($url, $host, false);
        }
        return self::classify($response);
    }

    /**
     * Make a request, at least HOST_GAP after the previous one to the same host.
     *
     * @param string $url
     * @param string $host
     * @param bool $head
     * @return array
     */
    private function request(string $url, string $host, bool $head): array {
        if (isset($this->lastrequest[$host])) {
            $wait = (int) (($this->lastrequest[$host] + self::HOST_GAP / 1000000 - microtime(true)) * 1000000);
            if ($wait > 0) {
                $this->http->wait($wait);
            }
        }
        $response = $this->http->request($url, $head);
        $this->lastrequest[$host] = microtime(true);
        return $response;
    }

    /**
     * Turn a response into a result.
     *
     * @param array $response Keys code, errno, blocked.
     * @return array
     */
    public static function classify(array $response): array {
        $code = (int) $response['code'];
        $errno = (int) $response['errno'];
        if (!empty($response['blocked'])) {
            return self::result(self::KIND_EXTERNAL, self::STATUS_SKIPPED, 0, 'blocked');
        }
        if ($errno) {
            return match (true) {
                // The body was larger than we read: the file is there.
                $errno === 63 => self::result(self::KIND_EXTERNAL, self::STATUS_OK, $code),
                $errno === 6 => self::result(self::KIND_EXTERNAL, self::STATUS_BROKEN, 0, 'dns'),
                $errno === 28 => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'timeout'),
                in_array($errno, [35, 51, 53, 54, 58, 59, 60, 77, 83, 90, 91], true)
                    => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'certificate'),
                default => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'connection'),
            };
        }
        return match (true) {
            $code >= 200 && $code < 400 => self::result(self::KIND_EXTERNAL, self::STATUS_OK, $code),
            in_array($code, [404, 410], true) => self::result(self::KIND_EXTERNAL, self::STATUS_BROKEN, $code, 'notfound'),
            in_array($code, [401, 403, 407, 429], true)
                => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'access'),
            $code >= 500 => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'server'),
            default => self::result(self::KIND_EXTERNAL, self::STATUS_UNVERIFIED, $code, 'http'),
        };
    }
}
