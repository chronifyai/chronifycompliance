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

namespace local_chronifycompliance\tests;

use local_chronifycompliance\links\http_client;

/**
 * HTTP client that answers from a table, so link check tests never use the network.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_http_client extends http_client {
    /** @var bool[] Hosts that exist; others do not. */
    public array $hosts = [];
    /** @var array[] Responses by "HEAD url" or "GET url"; missing ones answer 200. */
    public array $responses = [];
    /** @var string[] Requests made, as "HEAD url" or "GET url". */
    public array $requests = [];
    /** @var int Microseconds waited. */
    public int $waited = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(1);
    }

    /**
     * Whether a host exists.
     *
     * @param string $host
     * @return bool
     */
    public function host_exists(string $host): bool {
        return !empty($this->hosts[$host]);
    }

    /**
     * Answer from the table.
     *
     * @param string $url
     * @param bool $head
     * @return array
     */
    public function request(string $url, bool $head): array {
        $key = ($head ? 'HEAD ' : 'GET ') . $url;
        $this->requests[] = $key;
        return ($this->responses[$key] ?? []) + ['code' => 200, 'errno' => 0, 'blocked' => false];
    }

    /**
     * Record the wait instead of sleeping.
     *
     * @param int $microseconds
     */
    public function wait(int $microseconds): void {
        $this->waited += $microseconds;
    }
}
