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

use completion_info;
use course_modinfo;
use stdClass;

/**
 * Data shared by all checks of one audit, loaded lazily and at most once.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_context {
    /** @var course_modinfo|null Lazily loaded modinfo. */
    private ?course_modinfo $modinfo = null;
    /** @var completion_info|null Lazily loaded completion info. */
    private ?completion_info $completion = null;
    /** @var stdClass[]|null Rows of course_completion_criteria keyed by id. */
    private ?array $criteria = null;
    /** @var \grade_item[]|null Grade items of the course keyed by id. */
    private ?array $gradeitems = null;
    /** @var array Values memoised by checks, see remember(). */
    private array $memo = [];

    /**
     * Create the context.
     *
     * @param stdClass $course Course record (the site course for site audits).
     * @param bool $save Whether the audit is saved; a dry run writes nothing.
     */
    public function __construct(
        /** @var stdClass Course record. */
        public readonly stdClass $course,
        /** @var bool Whether the audit is saved; a dry run writes nothing, not even package analyses. */
        public readonly bool $save = true,
    ) {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
    }

    /**
     * Whether this is a site-level audit.
     *
     * @return bool
     */
    public function is_site(): bool {
        return (int) $this->course->id === (int) SITEID;
    }

    /**
     * Course module info, as seen by the system (not by a specific user).
     *
     * @return course_modinfo
     */
    public function get_modinfo(): course_modinfo {
        if ($this->modinfo === null) {
            $this->modinfo = get_fast_modinfo($this->course, -1);
        }
        return $this->modinfo;
    }

    /**
     * Completion info of the course.
     *
     * @return completion_info
     */
    public function get_completion(): completion_info {
        global $CFG;
        if ($this->completion === null) {
            require_once($CFG->libdir . '/completionlib.php');
            $this->completion = new completion_info($this->course);
        }
        return $this->completion;
    }

    /**
     * Course completion criteria rows.
     *
     * @param int|null $type Only criteria of this COMPLETION_CRITERIA_TYPE_* type.
     * @return stdClass[] Keyed by id.
     */
    public function get_criteria(?int $type = null): array {
        global $DB;
        if ($this->criteria === null) {
            $this->criteria = $DB->get_records('course_completion_criteria', ['course' => $this->course->id], 'id');
        }
        if ($type === null) {
            return $this->criteria;
        }
        return array_filter($this->criteria, fn(stdClass $c): bool => (int) $c->criteriatype === $type);
    }

    /**
     * Grade items of the course.
     *
     * @return \grade_item[] Keyed by id.
     */
    public function get_grade_items(): array {
        global $CFG;
        if ($this->gradeitems === null) {
            require_once($CFG->libdir . '/gradelib.php');
            $this->gradeitems = \grade_item::fetch_all(['courseid' => $this->course->id]) ?: [];
        }
        return $this->gradeitems;
    }

    /**
     * Find a grade item of an activity.
     *
     * @param string $modname Module name.
     * @param int $instance Module instance id.
     * @param int $itemnumber Item number.
     * @return \grade_item|null
     */
    public function get_activity_grade_item(string $modname, int $instance, int $itemnumber = 0): ?\grade_item {
        foreach ($this->get_grade_items() as $item) {
            if (
                $item->itemtype === 'mod' && $item->itemmodule === $modname
                    && (int) $item->iteminstance === $instance && (int) $item->itemnumber === $itemnumber
            ) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Compute a value once per audit and share it between checks.
     *
     * @param string $key Unique key, prefix it with the check id when it is check-specific.
     * @param callable $loader Function computing the value.
     * @return mixed
     */
    public function remember(string $key, callable $loader): mixed {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $loader($this);
        }
        return $this->memo[$key];
    }
}
