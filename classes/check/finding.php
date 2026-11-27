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

namespace local_completiondoctor\check;

use stdClass;

/**
 * A problem detected by a check.
 *
 * A finding is identified by its fingerprint, so the same problem detected again on a later
 * audit updates the existing row (and keeps an "ignored" status) instead of adding a new one.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding {
    /** @var string Finding is active. */
    public const STATUS_OPEN = 'open';
    /** @var string A user decided to ignore the finding. */
    public const STATUS_IGNORED = 'ignored';
    /** @var string The finding was not detected by the last audit. */
    public const STATUS_RESOLVED = 'resolved';
    /** @var string The finding was fixed through Completion Doctor. */
    public const STATUS_FIXED = 'fixed';

    /** @var int|null Database id, null until stored. */
    public ?int $id = null;
    /** @var string Current status. */
    public string $status = self::STATUS_OPEN;
    /** @var string|null Reason given when ignored. */
    public ?string $ignorereason = null;
    /** @var int|null User who ignored the finding. */
    public ?int $ignoredby = null;
    /** @var int|null Time the finding was ignored. */
    public ?int $timeignored = null;
    /** @var int Time created. */
    public int $timecreated = 0;
    /** @var int Time modified. */
    public int $timemodified = 0;
    /** @var int Time of the last audit that detected the finding. */
    public int $lastseen = 0;
    /** @var int Severity the check gave; $severity is this or the administrator's override. */
    public int $baseseverity = 0;
    /** @var string[] Message parameters that hold names (activity, course): formatted when the message is shown. */
    private const NAME_PARAMS = ['name', 'names', 'coursename', 'sectionname'];

    /**
     * Create a finding.
     *
     * @param string $checkid Id of the check producing the finding.
     * @param int $courseid Course id, or SITEID for site findings.
     * @param int $severity One of the base_check::SEVERITY_* constants.
     * @param string $messagekey Language string identifier of the message.
     * @param array $messageparams Parameters for the message string.
     * @param array $details Extra data for the report and for fixes.
     * @param int|null $cmid Course module the finding is about.
     * @param bool $fixable Whether the check can fix it.
     * @param array $identity Details that distinguish this finding from others of the same check and module.
     * @param string $component Component owning the check and its strings.
     */
    public function __construct(
        /** @var string Id of the check producing the finding. */
        public string $checkid,
        /** @var int Course id, or SITEID for site findings. */
        public int $courseid,
        /** @var int Severity. */
        public int $severity,
        /** @var string Language string identifier of the message. */
        public string $messagekey,
        /** @var array Parameters for the message string. */
        public array $messageparams = [],
        /** @var array Extra data for the report and for fixes. */
        public array $details = [],
        /** @var int|null Course module the finding is about. */
        public ?int $cmid = null,
        /** @var bool Whether the check can fix it. */
        public bool $fixable = false,
        /** @var array Details that make the fingerprint unique. */
        public array $identity = [],
        /** @var string Component owning the check and its strings. */
        public string $component = 'local_completiondoctor',
    ) {
        $this->baseseverity = $severity;
    }

    /**
     * Stable identity of the finding across audits.
     *
     * @return string SHA1 hash.
     */
    public function get_fingerprint(): string {
        $identity = $this->identity;
        ksort($identity);
        return sha1(implode('|', [
            $this->checkid,
            $this->courseid,
            (int) $this->cmid,
            json_encode($identity),
        ]));
    }

    /**
     * Get the message in the current language.
     *
     * @return string
     */
    public function get_message(): string {
        $manager = get_string_manager();
        if (!$manager->string_exists($this->messagekey, $this->component)) {
            return $this->checkid . ': ' . $this->messagekey;
        }
        $context = $this->courseid && $this->courseid !== (int) SITEID
            ? \context_course::instance($this->courseid, IGNORE_MISSING) : null;
        $context = $context ?: \context_system::instance();
        $params = [];
        foreach ($this->messageparams as $key => $value) {
            if (in_array($key, self::NAME_PARAMS, true) && is_string($value)) {
                $params[$key] = format_string($value, true, ['context' => $context, 'escape' => false]);
            } else {
                $params[$key] = self::render_reference($value, $this->component);
            }
        }
        return get_string($this->messagekey, $this->component, (object) $params);
    }

    /**
     * Render a message parameter in the current language, at display time. A parameter can be:
     * a language string reference ['str' => key, 'a' => params]; a date ['date' => timestamp], shown in
     * the viewer's time zone; a course ['course' => id], shown by its name; a role ['role' => id,
     * 'contextid' => id], shown by its name or course alias; a chain of courses
     * ['courses' => [ids]], joined with arrows; or a list of them (joined with "; ").
     *
     * @param mixed $value
     * @param string $component Component of the language strings.
     * @return mixed
     */
    public static function render_reference(mixed $value, string $component = 'local_completiondoctor'): mixed {
        global $DB;
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['str'])) {
            $a = $value['a'] ?? null;
            if (is_array($a)) {
                foreach ($a as $key => $item) {
                    $a[$key] = in_array($key, self::NAME_PARAMS, true) && is_string($item)
                        ? format_string($item, true, ['context' => \context_system::instance(), 'escape' => false])
                        : self::render_reference($item, $component);
                }
                $a = (object) $a;
            }
            return get_string($value['str'], $component, $a);
        }
        if (array_key_exists('date', $value)) {
            return userdate((int) $value['date'], get_string('strftimedatetimeshort', 'langconfig'));
        }
        if (array_key_exists('role', $value)) {
            $role = $DB->get_record('role', ['id' => (int) $value['role']]);
            if (!$role) {
                return '#' . (int) $value['role'];
            }
            $context = \context::instance_by_id((int) ($value['contextid'] ?? SYSCONTEXTID), IGNORE_MISSING);
            if ($context) {
                $role->coursealias = $DB->get_field('role_names', 'name', ['roleid' => $role->id,
                    'contextid' => $context->id]) ?: null;
            }
            return role_get_name($role, $context ?: null);
        }
        if (array_key_exists('courses', $value)) {
            return implode(' → ', array_map(fn($id) => (string) self::render_reference(['course' => $id]), $value['courses']));
        }
        if (array_key_exists('course', $value)) {
            $name = $DB->get_field('course', 'fullname', ['id' => (int) $value['course']]);
            return $name === false ? '#' . (int) $value['course']
                : format_string($name, true, ['context' => \context_course::instance((int) $value['course']), 'escape' => false]);
        }
        if (array_is_list($value)) {
            return implode('; ', array_map(fn($item) => (string) self::render_reference($item, $component), $value));
        }
        return json_encode($value);
    }

    /**
     * Whether the finding is currently open.
     *
     * @return bool
     */
    public function is_open(): bool {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Convert to a database record.
     *
     * @return stdClass
     */
    public function to_record(): stdClass {
        return (object) [
            'id' => $this->id,
            'courseid' => $this->courseid,
            'cmid' => $this->cmid,
            'checkid' => $this->checkid,
            'component' => $this->component,
            'severity' => $this->severity,
            'baseseverity' => $this->baseseverity ?: $this->severity,
            'fingerprint' => $this->get_fingerprint(),
            'status' => $this->status,
            'messagekey' => $this->messagekey,
            'messageparams' => json_encode($this->messageparams),
            'details' => json_encode(['identity' => $this->identity, 'data' => $this->details]),
            'fixable' => (int) $this->fixable,
            'ignorereason' => $this->ignorereason,
            'ignoredby' => $this->ignoredby,
            'timeignored' => $this->timeignored,
            'timecreated' => $this->timecreated,
            'timemodified' => $this->timemodified,
            'lastseen' => $this->lastseen,
        ];
    }

    /**
     * Build a finding from a database record.
     *
     * @param stdClass $record Row of local_completiondoctor_finding.
     * @return self
     */
    public static function from_record(stdClass $record): self {
        $details = json_decode((string) $record->details, true) ?: [];
        $finding = new self(
            $record->checkid,
            (int) $record->courseid,
            (int) $record->severity,
            $record->messagekey,
            json_decode((string) $record->messageparams, true) ?: [],
            $details['data'] ?? [],
            $record->cmid === null ? null : (int) $record->cmid,
            (bool) $record->fixable,
            $details['identity'] ?? [],
            $record->component,
        );
        $finding->id = (int) $record->id;
        $finding->baseseverity = (int) ($record->baseseverity ?? $record->severity);
        $finding->status = $record->status;
        $finding->ignorereason = $record->ignorereason;
        $finding->ignoredby = $record->ignoredby === null ? null : (int) $record->ignoredby;
        $finding->timeignored = $record->timeignored === null ? null : (int) $record->timeignored;
        $finding->timecreated = (int) $record->timecreated;
        $finding->timemodified = (int) $record->timemodified;
        $finding->lastseen = (int) $record->lastseen;
        return $finding;
    }
}
