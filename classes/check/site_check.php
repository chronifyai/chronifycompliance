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

/**
 * Base class of the site checks. Site checks are also shown on Site administration > Reports > System status.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class site_check extends base_check {
    /**
     * Site scope.
     *
     * @return string
     */
    public function get_scope(): string {
        return self::SCOPE_SITE;
    }

    /**
     * Site checks belong to the Site and performance pack.
     *
     * @return string
     */
    public function get_pack(): string {
        return self::PACK_SITE;
    }

    /**
     * Site finding helper.
     *
     * @param string $messagekey
     * @param array $params
     * @param array $identity
     * @param int|null $severity
     * @return finding
     */
    protected function site_finding(
        string $messagekey,
        array $params = [],
        array $identity = [],
        ?int $severity = null
    ): finding {
        return $this->make_finding((int) SITEID, $messagekey, $params, [], null, $identity, $severity);
    }
}
