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
use stdClass;

/**
 * Base class of every Chronify.Compliance check.
 *
 * Checks are read-only: run() must never write to the database, trigger events or send messages.
 * Fixable checks implement preview_fix(), apply_fix() and undo_fix(). Those are only ever called by
 * {@see \local_chronifycompliance\fix\fixer}, which wraps them in a transaction and writes the fix log.
 *
 * Language strings are read from get_component() with the keys "check:<id>:name" and "check:<id>:desc".
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_check {
    /** @var int Informational. */
    public const SEVERITY_INFO = 1;
    /** @var int Something is probably wrong. */
    public const SEVERITY_WARNING = 2;
    /** @var int Learners are blocked or complete wrongly. */
    public const SEVERITY_CRITICAL = 3;

    /** @var string Check runs once for the whole site. */
    public const SCOPE_SITE = 'site';
    /** @var string Check runs per course. */
    public const SCOPE_COURSE = 'course';
    /** @var string Check runs per course and reports per activity. */
    public const SCOPE_ACTIVITY = 'activity';

    /**
     * Unique id of the check, [a-z0-9]+.
     *
     * @return string
     */
    abstract public function get_id(): string;

    /**
     * Scope of the check, one of the SCOPE_* constants.
     *
     * @return string
     */
    abstract public function get_scope(): string;

    /**
     * Severity used when no admin override is set.
     *
     * @return int
     */
    abstract public function get_default_severity(): int;

    /**
     * Run the check.
     *
     * @param audit_context $context Shared data for the course (or the site).
     * @return finding[]
     */
    abstract public function run(audit_context $context): array;

    /**
     * Component owning the check and its language strings.
     *
     * @return string
     */
    public function get_component(): string {
        return 'local_chronifycompliance';
    }

    /**
     * Name of the check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check:' . $this->get_id() . ':name', $this->get_component());
    }

    /**
     * Description of the check in teacher language.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('check:' . $this->get_id() . ':desc', $this->get_component());
    }

    /**
     * Severity, taking the admin override into account.
     *
     * @return int
     */
    public function get_severity(): int {
        return $this->get_severity_override() ?? $this->get_default_severity();
    }

    /**
     * Severity the administrator set for every finding of this check, if any.
     *
     * @return int|null
     */
    public function get_severity_override(): ?int {
        $override = (int) get_config('local_chronifycompliance', 'severity_' . $this->get_id());
        return in_array($override, [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL], true)
            ? $override : null;
    }

    /**
     * Whether the check is enabled by the admin.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return get_config('local_chronifycompliance', 'enabled_' . $this->get_id()) !== '0';
    }

    /**
     * Whether the check is relevant for the course. Called before run().
     *
     * @param stdClass $course
     * @return bool
     */
    public function applies_to_course(stdClass $course): bool {
        return true;
    }

    /**
     * Whether the check can fix the finding.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return false;
    }

    /**
     * Parameters the user must provide for the fix, shown in the preview form.
     *
     * @param finding $finding
     * @return array[] Each with keys name, type (PARAM_*), label and default.
     */
    public function get_fix_parameters(finding $finding): array {
        return [];
    }

    /**
     * Describe the change a fix would make. Must not write anything.
     *
     * @param finding $finding
     * @param array $params User supplied parameters (for example a pass grade).
     * @return fix_preview
     * @throws \coding_exception When the check cannot fix the finding.
     * @throws \invalid_parameter_exception When parameters are invalid.
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        throw new \coding_exception('Check ' . $this->get_id() . ' cannot fix findings');
    }

    /**
     * Apply a previously previewed fix. Only called by the fixer, inside a transaction.
     *
     * @param finding $finding
     * @param fix_preview $preview Fresh preview built with the same parameters.
     * @param int $userid User applying the fix.
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        throw new \coding_exception('Check ' . $this->get_id() . ' cannot fix findings');
    }

    /**
     * Revert a fix. Only called by the fixer, inside a transaction.
     *
     * @param string $fixtype Fix type stored in the log.
     * @param array $before Values before the fix.
     * @param array $after Values after the fix.
     * @param stdClass $fixlog The fix log record.
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, stdClass $fixlog): fix_result {
        throw new \coding_exception('Check ' . $this->get_id() . ' cannot undo fixes');
    }

    /**
     * Helper to create a finding of this check.
     *
     * @param int $courseid
     * @param string $messagekey Language string id in get_component().
     * @param array $messageparams
     * @param array $details
     * @param int|null $cmid
     * @param array $identity Extra fingerprint keys.
     * @param int|null $severity Severity, or null for get_severity().
     * @return finding
     */
    protected function make_finding(
        int $courseid,
        string $messagekey,
        array $messageparams = [],
        array $details = [],
        ?int $cmid = null,
        array $identity = [],
        ?int $severity = null,
    ): finding {
        // A check may give each case its own severity; the administrator's setting overrides all of them.
        $base = $severity ?? $this->get_default_severity();
        $finding = new finding(
            $this->get_id(),
            $courseid,
            $base,
            $messagekey,
            $messageparams,
            $details,
            $cmid,
            false,
            $identity,
            $this->get_component(),
        );
        $finding->severity = $this->get_severity_override() ?? $base;
        $finding->fixable = $this->can_fix($finding);
        return $finding;
    }

    /**
     * Refuse an undo when the values changed since the fix (someone edited them afterwards).
     *
     * @param array $current Current values.
     * @param array $expected Values the fix left, for the same keys.
     * @throws \moodle_exception error:undochanged
     */
    protected function assert_unchanged(array $current, array $expected): void {
        foreach ($expected as $key => $value) {
            $now = $current[$key] ?? null;
            $same = ($now === null || $value === null) ? $now === $value : abs((float) $now - (float) $value) < 0.00001;
            if (!$same) {
                throw new \moodle_exception('error:undochanged', 'local_chronifycompliance');
            }
        }
    }
}
