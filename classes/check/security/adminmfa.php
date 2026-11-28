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
use local_chronifycompliance\check\guided_fix;
use local_chronifycompliance\check\security_check;

/**
 * Site administrators can sign in with a password only: multi-factor authentication is off, or some
 * administrators have no second factor set up. Fixed with a guided wizard that turns MFA on with the chosen
 * factors and a grace period, so nobody is locked out.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adminmfa extends security_check implements guided_fix {
    /** @var string[] Factors a user sets up for themselves (a device, an app, a phone). */
    public const SETUP_FACTORS = ['totp', 'webauthn', 'sms'];
    /** @var string[] Factors the wizard offers. */
    public const WIZARD_FACTORS = ['totp', 'webauthn', 'email'];
    /** @var int Grace period the wizard suggests, in days. */
    public const GRACE_DAYS = 7;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'adminmfa';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB;
        $admins = array_keys(get_admins());
        $url = new \moodle_url('/admin/category.php', ['category' => 'toolmfafolder']);
        if (!\core_component::get_component_directory('tool_mfa') || !get_config('tool_mfa', 'enabled')) {
            return [$this->security_finding('check:adminmfa:messageoff', ['count' => count($admins)], $url)];
        }
        // The email factor needs no set up: every administrator with an email address has it.
        if (get_config('factor_email', 'enabled')) {
            return [];
        }
        $factors = array_values(array_filter(self::SETUP_FACTORS, fn($f) => (bool) get_config('factor_' . $f, 'enabled')));
        $without = $admins;
        if ($factors && $admins) {
            [$userin, $params] = $DB->get_in_or_equal($admins, SQL_PARAMS_NAMED, 'u');
            [$factorin, $fparams] = $DB->get_in_or_equal($factors, SQL_PARAMS_NAMED, 'f');
            $with = $DB->get_fieldset_select(
                'tool_mfa',
                'DISTINCT userid',
                "userid {$userin} AND factor {$factorin} AND revoked = 0",
                $params + $fparams
            );
            $without = array_diff($admins, array_map('intval', $with));
        }
        if (!$without) {
            return [];
        }
        return [$this->security_finding('check:adminmfa:message', ['count' => count($without),
            'total' => count($admins)], $url)];
    }

    /**
     * The wizard can fix every finding of this check, when Moodle has the MFA plugin.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return (bool) \core_component::get_component_directory('tool_mfa');
    }

    /**
     * Factors the wizard can turn on on this site.
     *
     * @return string[]
     */
    private static function available_factors(): array {
        return array_values(array_filter(
            self::WIZARD_FACTORS,
            fn($factor) => (bool) \core_component::get_component_directory('factor_' . $factor)
        ));
    }

    /**
     * Questions of the wizard.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_fix_parameters(finding $finding): array {
        $component = 'local_chronifycompliance';
        $fields = [];
        foreach (self::available_factors() as $factor) {
            $enabled = (bool) get_config('factor_' . $factor, 'enabled');
            $fields[] = ['name' => 'factor_' . $factor, 'type' => PARAM_BOOL, 'element' => 'checkbox',
                'label' => get_string('guided:mfa:factor_' . $factor, $component),
                'help' => get_string('guided:mfa:factor_' . $factor . ':help', $component),
                'default' => $enabled || $factor === 'totp' ? 1 : 0];
        }
        $fields[] = ['name' => 'adminsonly', 'type' => PARAM_BOOL, 'element' => 'checkbox',
            'label' => get_string('guided:mfa:adminsonly', $component),
            'help' => get_string('guided:mfa:adminsonly:help', $component), 'default' => 1];
        $fields[] = ['name' => 'grace', 'type' => PARAM_BOOL, 'element' => 'checkbox',
            'label' => get_string('guided:mfa:grace', $component), 'help' => get_string('guided:mfa:grace:help', $component),
            'default' => 1];
        $fields[] = ['name' => 'gracedays', 'type' => PARAM_INT, 'element' => 'text',
            'label' => get_string('guided:mfa:gracedays', $component),
            'help' => get_string('guided:mfa:gracedays:help', $component, ['min' => 1, 'max' => 30]),
            'default' => self::GRACE_DAYS, 'min' => 1, 'max' => 30];
        return $fields;
    }

    /**
     * Steps of the wizard.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_guided_steps(finding $finding): array {
        return [
            ['key' => 'factors', 'title' => 'guided:mfa:step:factors', 'intro' => 'guided:mfa:step:factors:intro',
                'fields' => array_map(fn($f) => 'factor_' . $f, self::available_factors())],
            ['key' => 'grace', 'title' => 'guided:mfa:step:grace', 'intro' => 'guided:mfa:step:grace:intro',
                'fields' => ['adminsonly', 'grace', 'gracedays']],
        ];
    }

    /**
     * The settings the answers set: MFA on, the chosen factors on (the others are left as they are), and the
     * grace period.
     *
     * @param finding $finding
     * @param array $params
     * @return array[]
     */
    protected function fix_settings(finding $finding, array $params): array {
        $chosen = array_filter(self::available_factors(), fn($factor) => !empty($params['factor_' . $factor]));
        if (!$chosen) {
            throw new \moodle_exception('guided:error:nofactor', 'local_chronifycompliance');
        }
        $settings = [['name' => 'enabled', 'plugin' => 'tool_mfa', 'value' => '1', 'label' => 'guided:mfa:enabled']];
        foreach ($chosen as $factor) {
            $settings[] = ['name' => 'enabled', 'plugin' => 'factor_' . $factor, 'value' => '1',
                'label' => 'guided:mfa:factor_' . $factor];
        }
        // The "Non-administrator" factor lets everyone else in without a second factor.
        if (!empty($params['adminsonly']) && \core_component::get_component_directory('factor_admin')) {
            $settings[] = ['name' => 'enabled', 'plugin' => 'factor_admin', 'value' => '1', 'label' => 'guided:mfa:adminsonly'];
        }
        if (!empty($params['grace']) && \core_component::get_component_directory('factor_grace')) {
            $days = (int) ($params['gracedays'] ?? self::GRACE_DAYS);
            if ($days < 1 || $days > 30) {
                throw new \moodle_exception(
                    'guided:error:range',
                    'local_chronifycompliance',
                    '',
                    ['label' => get_string('guided:mfa:gracedays', 'local_chronifycompliance'), 'min' => 1, 'max' => 30]
                );
            }
            $settings[] = ['name' => 'enabled', 'plugin' => 'factor_grace', 'value' => '1', 'label' => 'guided:mfa:grace'];
            $settings[] = ['name' => 'graceperiod', 'plugin' => 'factor_grace', 'value' => (string) ($days * DAYSECS),
                'label' => 'guided:mfa:graceperiod', 'type' => 'text'];
        }
        return $settings;
    }

    /**
     * What turning MFA on means for administrators and users.
     *
     * @param finding $finding
     * @param array $params
     * @return string[]
     */
    protected function fix_warnings(finding $finding, array $params): array {
        $component = 'local_chronifycompliance';
        $warnings = [get_string('guided:mfa:warning', $component)];
        if (empty($params['adminsonly'])) {
            $warnings[] = get_string('guided:mfa:warningeveryone', $component);
        }
        if (empty($params['grace'])) {
            $warnings[] = get_string('guided:mfa:warningnograce', $component);
        }
        return $warnings;
    }
}
