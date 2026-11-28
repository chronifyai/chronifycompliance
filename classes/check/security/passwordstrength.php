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
 * The password policy is off, or on but weak: short passwords, or no lockout after failed sign ins. Fixed with a
 * guided wizard. Moodle's own "password policy" security check is not read twice (`coresecurity` skips it).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passwordstrength extends security_check implements guided_fix {
    /** @var int Shortest password length that counts as strong. */
    public const MIN_LENGTH = 8;
    /** @var int Length the wizard suggests. */
    public const SUGGESTED_LENGTH = 12;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'passwordstrength';
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
        global $CFG;
        $findings = [];
        if (empty($CFG->passwordpolicy)) {
            $findings[] = $this->security_finding(
                'check:passwordstrength:messageoff',
                [],
                self::settings('sitepolicies'),
                ['case' => 'off']
            );
        } else if ((int) ($CFG->minpasswordlength ?? 0) < self::MIN_LENGTH) {
            $findings[] = $this->security_finding(
                'check:passwordstrength:messagelength',
                ['length' => (int) ($CFG->minpasswordlength ?? 0), 'min' => self::MIN_LENGTH],
                self::settings('sitepolicies'),
                ['case' => 'length']
            );
        }
        if (empty($CFG->lockoutthreshold)) {
            $findings[] = $this->security_finding(
                'check:passwordstrength:messagelockout',
                [],
                self::settings('sitepolicies'),
                ['case' => 'lockout']
            );
        }
        return $findings;
    }

    /**
     * Every finding of this check is fixed by the wizard.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return true;
    }

    /**
     * Questions of the wizard, with safe suggestions as defaults.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_fix_parameters(finding $finding): array {
        global $CFG;
        $component = 'local_chronifycompliance';
        $int = fn(string $name, int $default, int $min, int $max) => [
            'name' => $name,
            'type' => PARAM_INT,
            'element' => 'text',
            'label' => get_string('guided:password:' . $name, $component),
            'help' => get_string('guided:password:' . $name . ':help', $component, ['min' => $min, 'max' => $max]),
            'default' => $default,
            'min' => $min,
            'max' => $max,
        ];
        return [
            $int('minpasswordlength', max(self::SUGGESTED_LENGTH, (int) ($CFG->minpasswordlength ?? 0)), self::MIN_LENGTH, 64),
            $int('minpassworddigits', max(1, (int) ($CFG->minpassworddigits ?? 0)), 0, 10),
            $int('minpasswordlower', max(1, (int) ($CFG->minpasswordlower ?? 0)), 0, 10),
            $int('minpasswordupper', max(1, (int) ($CFG->minpasswordupper ?? 0)), 0, 10),
            $int('minpasswordnonalphanum', max(1, (int) ($CFG->minpasswordnonalphanum ?? 0)), 0, 10),
            $int('lockoutthreshold', (int) ($CFG->lockoutthreshold ?? 0) ?: 10, 3, 50),
        ];
    }

    /**
     * Steps of the wizard.
     *
     * @param finding $finding
     * @return array[]
     */
    public function get_guided_steps(finding $finding): array {
        return [
            ['key' => 'length', 'title' => 'guided:password:step:length', 'intro' => 'guided:password:step:length:intro',
                'fields' => ['minpasswordlength', 'minpassworddigits', 'minpasswordlower', 'minpasswordupper',
                    'minpasswordnonalphanum']],
            ['key' => 'lockout', 'title' => 'guided:password:step:lockout', 'intro' => 'guided:password:step:lockout:intro',
                'fields' => ['lockoutthreshold']],
        ];
    }

    /**
     * The settings the answers set: the policy on, the rules, and the lockout.
     *
     * @param finding $finding
     * @param array $params
     * @return array[]
     */
    protected function fix_settings(finding $finding, array $params): array {
        $settings = [['name' => 'passwordpolicy', 'plugin' => null, 'value' => '1', 'label' => 'guided:password:passwordpolicy']];
        foreach ($this->get_fix_parameters($finding) as $field) {
            $value = $params[$field['name']] ?? $field['default'];
            if (!is_numeric($value) || (int) $value < $field['min'] || (int) $value > $field['max']) {
                throw new \moodle_exception(
                    'guided:error:range',
                    'local_chronifycompliance',
                    '',
                    ['label' => $field['label'], 'min' => $field['min'], 'max' => $field['max']]
                );
            }
            $settings[] = ['name' => $field['name'], 'plugin' => null, 'value' => (string) (int) $value,
                'label' => 'guided:password:' . $field['name'], 'type' => 'text'];
        }
        return $settings;
    }

    /**
     * What the change means for users.
     *
     * @param finding $finding
     * @param array $params
     * @return string[]
     */
    protected function fix_warnings(finding $finding, array $params): array {
        return [get_string('guided:password:warning', 'local_chronifycompliance')];
    }
}
