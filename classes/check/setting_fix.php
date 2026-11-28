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
 * One click fixes that change one site setting: preview with the value now and after and a warning, the change
 * logged in Config changes, and undo that is refused when the setting was changed again since.
 *
 * Used by site checks. A finding made with setting_finding() is fixable; other findings of the check are not.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait setting_fix {
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
     * The settings a fix changes, with the values it sets. By default the one setting of setting_finding(); guided
     * fixes build them from the answers of the wizard.
     *
     * @param finding $finding
     * @param array $params
     * @return array[] Each with keys name, plugin (null for core), value (string), label (language string) and
     *                 type (bool, shown as On or Off, or text, shown as it is; default bool).
     */
    protected function fix_settings(finding $finding, array $params): array {
        $fix = $finding->details['fix'] ?? null;
        if (!$fix) {
            throw new \coding_exception('Finding of ' . $this->get_id() . ' has no setting fix');
        }
        return [$fix];
    }

    /**
     * Current value of a setting.
     *
     * @param array $setting Keys name and plugin.
     * @return string
     */
    private static function current_value(array $setting): string {
        $value = get_config($setting['plugin'] ?? 'core', $setting['name']);
        return $value === false ? '' : (string) $value;
    }

    /**
     * Preview the setting changes. Writes nothing.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $before = [];
        $after = [];
        $rows = [];
        foreach ($this->fix_settings($finding, $params) as $setting) {
            $key = ['name' => $setting['name'], 'plugin' => $setting['plugin'] ?? null];
            $now = self::current_value($setting);
            $before[] = $key + ['value' => $now];
            $after[] = $key + ['value' => (string) $setting['value']];
            if ($now !== (string) $setting['value']) {
                $type = $setting['type'] ?? 'bool';
                $rows[] = [get_string($setting['label'], 'local_chronifycompliance'), self::display($now, $type),
                    self::display((string) $setting['value'], $type)];
            }
        }
        $preview = new fix_preview('settings', ['settings' => $before], ['settings' => $after]);
        foreach ($rows as [$label, $from, $to]) {
            $preview->add_row($label, $from, $to);
        }
        if (!$rows) {
            $preview->add_warning(get_string('fix:setting:nochange', 'local_chronifycompliance'));
        }
        $preview->add_warning(get_string('fix:setting:warning', 'local_chronifycompliance'));
        foreach ($this->fix_warnings($finding, $params) as $warning) {
            $preview->add_warning($warning);
        }
        return $preview;
    }

    /**
     * Extra warnings shown in the preview of the fix.
     *
     * @param finding $finding
     * @param array $params
     * @return string[]
     */
    protected function fix_warnings(finding $finding, array $params): array {
        return [];
    }

    /**
     * Change the settings, each logged in the config changes report like a change on the settings page.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        foreach ($preview->after['settings'] as $setting) {
            self::set_setting($setting, (string) $setting['value']);
        }
        return new fix_result(true, $preview->after);
    }

    /**
     * Put the settings back, unless someone changed one of them since the fix.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        // Fixes logged before guided fixes changed one setting, stored without the settings list.
        $before = $before['settings'] ?? [$before];
        $after = $after['settings'] ?? [$after];
        foreach ($after as $setting) {
            if (self::current_value($setting) !== (string) $setting['value']) {
                throw new \moodle_exception('error:undochanged', 'local_chronifycompliance');
            }
        }
        foreach ($before as $setting) {
            self::set_setting($setting, (string) $setting['value']);
        }
        return new fix_result(true, ['settings' => $before]);
    }

    /**
     * Set a setting and log the change.
     *
     * @param array $setting Keys name and plugin.
     * @param string $value
     */
    private static function set_setting(array $setting, string $value): void {
        $old = self::current_value($setting);
        if ($old === $value) {
            return;
        }
        set_config($setting['name'], $value, $setting['plugin'] ?? null);
        add_to_config_log($setting['name'], $old, $value, $setting['plugin'] ?? null);
        // MFA factors keep their own order list, which the factor settings page updates when a factor is turned
        // on or off.
        $plugin = (string) ($setting['plugin'] ?? '');
        if ($setting['name'] === 'enabled' && str_starts_with($plugin, 'factor_') && class_exists('\\tool_mfa\\manager')) {
            \tool_mfa\manager::do_factor_action(substr($plugin, 7), $value ? 'enable' : 'disable');
        }
    }

    /**
     * A setting value as shown in the preview.
     *
     * @param string $value
     * @param string $type bool or text.
     * @return string
     */
    private static function display(string $value, string $type = 'bool'): string {
        if ($type !== 'bool') {
            return $value === '' ? get_string('fix:setting:empty', 'local_chronifycompliance') : $value;
        }
        if ($value === '' || $value === '0') {
            return get_string('fix:setting:off', 'local_chronifycompliance');
        }
        return $value === '1' ? get_string('fix:setting:on', 'local_chronifycompliance') : $value;
    }
}
