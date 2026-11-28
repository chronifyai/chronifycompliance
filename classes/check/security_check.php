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
 * Base class of the checks of the Security pack. Site scope; read only. Findings link to the setting that fixes
 * them; nothing security related is changed without a preview and a confirmation.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class security_check extends site_check {
    /**
     * Security pack.
     *
     * @return string
     */
    public function get_pack(): string {
        return self::PACK_SECURITY;
    }

    /**
     * A site finding linking to an admin page.
     *
     * @param string $messagekey
     * @param array $params
     * @param \moodle_url $url Page with the setting that fixes it.
     * @param array $identity Distinguishes several findings of the check.
     * @param int|null $severity
     * @return finding
     */
    protected function security_finding(
        string $messagekey,
        array $params,
        \moodle_url $url,
        array $identity = [],
        ?int $severity = null
    ): finding {
        return $this->make_finding((int) SITEID, $messagekey, $params, ['url' => $url->out(false)], null, $identity, $severity);
    }

    /**
     * Site settings page of a section.
     *
     * @param string $section
     * @return \moodle_url
     */
    protected static function settings(string $section): \moodle_url {
        return new \moodle_url('/admin/settings.php', ['section' => $section]);
    }
}
