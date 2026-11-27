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

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;

/**
 * Configurable check used by the engine tests.
 *
 * run() returns whatever the static $runner returns. Its fix toggles course.showreports.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_check extends base_check {
    /** @var callable|null Function (audit_context, fake_check): finding[]. */
    public static $runner = null;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'fakecheck';
    }

    /**
     * Scope.
     *
     * @return string
     */
    public function get_scope(): string {
        return self::SCOPE_COURSE;
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
     * Name without language string.
     *
     * @return string
     */
    public function get_name(): string {
        return 'Fake check';
    }

    /**
     * Description without language string.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Fake check for tests';
    }

    /**
     * Run the configured runner.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        return self::$runner ? (self::$runner)($context, $this) : [];
    }

    /**
     * Public wrapper around make_finding().
     *
     * @param int $courseid
     * @param string $key Identity key.
     * @param int|null $cmid
     * @param int|null $severity
     * @param bool $fixable
     * @return finding
     */
    public function emit(
        int $courseid,
        string $key = 'a',
        ?int $cmid = null,
        ?int $severity = null,
        bool $fixable = false
    ): finding {
        return $this->make_finding(
            $courseid,
            'fake:' . $key,
            ['key' => $key],
            ['fixable' => $fixable],
            $cmid,
            ['key' => $key],
            $severity
        );
    }

    /**
     * Fixable when created with $fixable.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['fixable']);
    }

    /**
     * Preview toggling course.showreports (or setting it to params['value']).
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        global $DB;
        $current = (int) $DB->get_field('course', 'showreports', ['id' => $finding->courseid], MUST_EXIST);
        if (isset($params['value']) && !in_array($params['value'], [0, 1], true)) {
            throw new \invalid_parameter_exception('value must be 0 or 1');
        }
        $new = $params['value'] ?? 1 - $current;
        $preview = new fix_preview('showreports', ['showreports' => $current], ['showreports' => $new], $params);
        return $preview->add_row('showreports', (string) $current, (string) $new);
    }

    /**
     * Apply the change.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        global $DB;
        $DB->set_field('course', 'showreports', $preview->after['showreports'], ['id' => $finding->courseid]);
        return new fix_result(true, $preview->after, 'done');
    }

    /**
     * Revert the change.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        global $DB;
        $DB->set_field('course', 'showreports', $before['showreports'], ['id' => $fixlog->courseid]);
        return new fix_result(true, $before, 'undone');
    }
}
