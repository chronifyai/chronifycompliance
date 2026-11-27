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

namespace local_chronifycompliance\output;

use moodle_url;
use renderer_base;

/**
 * One quiet, dismissible invitation at the bottom of a workspace, shown after its results.
 *
 * Never shown when the administrator turned invitations off (setting "invitations"), or for 30 days after
 * the user dismissed it. One invitation per page; the page decides which.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invitation implements \core\output\named_templatable, \renderable {
    /** @var string[] Invitation keys; each has a user preference for its dismissal. */
    public const KEYS = ['dashboard', 'completion', 'security', 'reports'];
    /** @var int Seconds an invitation stays hidden after it was dismissed. */
    public const DISMISS_FOR = 30 * DAYSECS;

    /**
     * Constructor.
     *
     * @param string $key One of KEYS.
     * @param moodle_url $url Page the invitation links to (opens in a new tab).
     */
    private function __construct(
        /** @var string Invitation key. */
        private string $key,
        /** @var moodle_url Link. */
        private moodle_url $url
    ) {
    }

    /**
     * The invitation for a page, or null when it should not be shown to the current user.
     *
     * @param string $key One of KEYS.
     * @param moodle_url $url Page the invitation links to.
     * @return self|null
     */
    public static function for_page(string $key, moodle_url $url): ?self {
        if (!in_array($key, self::KEYS, true)) {
            throw new \coding_exception('Unknown invitation ' . $key);
        }
        if (!self::enabled()) {
            return null;
        }
        $dismissed = (int) get_user_preferences(self::preference($key), 0);
        if ($dismissed > time() - self::DISMISS_FOR) {
            return null;
        }
        return new self($key, $url);
    }

    /**
     * Whether the administrator allows invitations (on by default).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config('local_chronifycompliance', 'invitations') !== '0';
    }

    /**
     * Name of the user preference holding the dismissal time of an invitation.
     *
     * @param string $key
     * @return string
     */
    public static function preference(string $key): string {
        return 'local_chronifycompliance_invite_' . $key;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/invitation';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'key' => $this->key,
            'preference' => self::preference($this->key),
            'message' => get_string('invite:' . $this->key, 'local_chronifycompliance'),
            'linktext' => get_string('invite:' . $this->key . ':link', 'local_chronifycompliance'),
            'url' => $this->url->out(false),
        ];
    }
}
