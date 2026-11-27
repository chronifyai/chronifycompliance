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

namespace local_chronifycompliance\check\completion;

use local_chronifycompliance\check\audit_context;
use local_chronifycompliance\check\course_check;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;

/**
 * Self completion is a criterion, but the course has no Self completion block, so learners have no
 * way to mark themselves complete.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selfcompletionblock extends course_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'selfcompletionblock';
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
        if (!$context->get_criteria(COMPLETION_CRITERIA_TYPE_SELF) || self::is_block_shown($context->course)) {
            return [];
        }
        $enabled = (bool) $DB->get_field('block', 'visible', ['name' => 'selfcompletion']);
        return [$this->make_finding(
            (int) $context->course->id,
            $enabled ? 'check:selfcompletionblock:message' : 'check:selfcompletionblock:messagedisabled',
            [],
            ['blockenabled' => $enabled]
        )];
    }

    /**
     * Whether the course page has a Self completion block: one added to the course, or one added to the
     * site or a category with "display throughout" on a page type that matches the course page.
     *
     * @param \stdClass $course
     * @return bool
     */
    private static function is_block_shown(\stdClass $course): bool {
        global $CFG, $DB;
        require_once($CFG->libdir . '/blocklib.php');
        $coursecontext = \context_course::instance($course->id);
        [$parentsql, $params] = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(), SQL_PARAMS_NAMED, 'ctx');
        [$patternsql, $patternparams] = $DB->get_in_or_equal(
            matching_page_type_patterns('course-view-' . $course->format),
            SQL_PARAMS_NAMED,
            'pt'
        );
        return $DB->record_exists_select(
            'block_instances',
            "blockname = :blockname AND (parentcontextid = :coursecontextid
                OR (parentcontextid {$parentsql} AND showinsubcontexts = 1 AND pagetypepattern {$patternsql}))",
            ['blockname' => 'selfcompletion', 'coursecontextid' => $coursecontext->id] + $params + $patternparams
        );
    }

    /**
     * Fixable when the block is enabled on the site.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['blockenabled']);
    }

    /**
     * Preview adding the block.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $preview = new fix_preview('addselfcompletionblock', ['blockinstanceid' => null], ['courseid' => $finding->courseid]);
        return $preview->add_row(get_string('pluginname', 'block_selfcompletion'), get_string('no'), get_string('yes'));
    }

    /**
     * Add the block to the course page.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        global $DB;
        $course = get_course($finding->courseid);
        $page = new \moodle_page();
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $course->format);
        $page->blocks->load_blocks();
        $page->blocks->add_block_at_end_of_default_region('selfcompletion');
        $coursecontext = \context_course::instance($course->id);
        $instanceid = (int) $DB->get_field_sql(
            "SELECT MAX(id) FROM {block_instances} WHERE blockname = 'selfcompletion' AND parentcontextid = :ctx",
            ['ctx' => $coursecontext->id]
        );
        return new fix_result((bool) $instanceid, ['blockinstanceid' => $instanceid, 'courseid' => $course->id]);
    }

    /**
     * Remove the added block.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        global $DB;
        $instance = $DB->get_record('block_instances', ['id' => $after['blockinstanceid'] ?? 0, 'blockname' => 'selfcompletion']);
        if (!$instance) {
            throw new \moodle_exception('error:undochanged', 'local_chronifycompliance');
        }
        blocks_delete_instance($instance);
        return new fix_result(true, $before);
    }
}
