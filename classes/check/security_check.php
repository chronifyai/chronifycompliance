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

use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;

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

    /**
     * A finding that a one click fix can resolve by changing one setting.
     *
     * @param string $messagekey
     * @param array $params
     * @param \moodle_url $url
     * @param string $name Setting name.
     * @param string|null $plugin Setting plugin, null for core.
     * @param string $value Safe value the fix sets.
     * @param string $label Language string (this plugin) naming the setting in the preview.
     * @param array $identity
     * @param int|null $severity
     * @return finding
     */
    protected function setting_finding(
        string $messagekey,
        array $params,
        \moodle_url $url,
        string $name,
        ?string $plugin,
        string $value,
        string $label,
        array $identity = [],
        ?int $severity = null
    ): finding {
        return $this->make_finding((int) SITEID, $messagekey, $params, [
            'url' => $url->out(false),
            'fix' => ['name' => $name, 'plugin' => $plugin, 'value' => $value, 'label' => $label],
        ], null, $identity, $severity);
    }

    /**
     * Findings made by setting_finding() can be fixed.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['fix']['name']);
    }

    /**
     * Current value of a setting.
     *
     * @param array $fix Keys name and plugin.
     * @return string
     */
    private static function current_value(array $fix): string {
        $value = get_config($fix['plugin'] ?? 'core', $fix['name']);
        return $value === false ? '' : (string) $value;
    }

    /**
     * Preview the setting change. Writes nothing.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $fix = $finding->details['fix'] ?? null;
        if (!$fix) {
            throw new \coding_exception('Finding of ' . $this->get_id() . ' has no setting fix');
        }
        $key = ['name' => $fix['name'], 'plugin' => $fix['plugin']];
        $preview = new fix_preview(
            'setting',
            $key + ['value' => self::current_value($fix)],
            $key + ['value' => (string) $fix['value']]
        );
        $preview->add_row(
            get_string($fix['label'], 'local_chronifycompliance'),
            self::display(self::current_value($fix)),
            self::display((string) $fix['value'])
        );
        $preview->add_warning(get_string('fix:setting:warning', 'local_chronifycompliance'));
        return $preview;
    }

    /**
     * Change the setting, logged in the config changes report like a change on the settings page.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        self::set_setting($preview->before, (string) $preview->after['value']);
        return new fix_result(true, $preview->after);
    }

    /**
     * Put the setting back, unless someone changed it since the fix.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        if (self::current_value($after) !== (string) $after['value']) {
            throw new \moodle_exception('error:undochanged', 'local_chronifycompliance');
        }
        self::set_setting($after, (string) $before['value']);
        return new fix_result(true, $before);
    }

    /**
     * Set a setting and log the change.
     *
     * @param array $fix Keys name and plugin.
     * @param string $value
     */
    private static function set_setting(array $fix, string $value): void {
        $old = self::current_value($fix);
        set_config($fix['name'], $value, $fix['plugin'] ?? null);
        add_to_config_log($fix['name'], $old, $value, $fix['plugin'] ?? null);
    }

    /**
     * A setting value as shown in the preview.
     *
     * @param string $value
     * @return string
     */
    private static function display(string $value): string {
        if ($value === '' || $value === '0') {
            return get_string('fix:setting:off', 'local_chronifycompliance');
        }
        return $value === '1' ? get_string('fix:setting:on', 'local_chronifycompliance') : $value;
    }
}
