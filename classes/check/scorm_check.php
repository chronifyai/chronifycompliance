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

use cm_info;
use local_chronifycompliance\fix\activity_completion;
use local_chronifycompliance\fix\fix_preview;
use local_chronifycompliance\fix\fix_result;
use local_chronifycompliance\scorm\inspector;

/**
 * Base class of the SCORM checks, with the shared "apply settings" fix.
 *
 * Fixable SCORM findings store the recommended scorm table values in details['recommended'].
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class scorm_check extends course_check {
    /** @var string[] Fields of the scorm table that fixes may change. */
    protected const FIXABLE_FIELDS = ['completionstatusrequired', 'completionscorerequired', 'completionstatusallscos',
        'masteryoverride', 'forcenewattempt'];

    /**
     * Activity scope.
     *
     * @return string
     */
    public function get_scope(): string {
        return self::SCOPE_ACTIVITY;
    }

    /**
     * SCORM activities of the course that are not being deleted.
     *
     * @param audit_context $context
     * @param bool $automaticonly Only activities with automatic completion.
     * @return cm_info[]
     */
    protected function get_scorm_cms(audit_context $context, bool $automaticonly = true): array {
        $cms = [];
        foreach ($context->get_modinfo()->get_instances_of('scorm') as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if ($automaticonly && $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
                continue;
            }
            $cms[$cm->id] = $cm;
        }
        return $cms;
    }

    /**
     * Inspection of a SCORM activity, shared by all SCORM checks of the audit.
     *
     * @param audit_context $context
     * @param cm_info $cm
     * @return array See inspector::inspect().
     */
    protected function inspect(audit_context $context, cm_info $cm): array {
        // Audits never analyze big packages inline: they are queued (see package_analyzer::analyze_or_queue).
        // A dry run analyzes small packages in memory only.
        $mode = $context->save ? inspector::MODE_AUDIT : inspector::MODE_DRYRUN;
        return $context->remember('scorm:' . $cm->id, fn() => inspector::inspect($cm, $mode));
    }

    /**
     * Human value of a scorm setting.
     *
     * @param string $field
     * @param mixed $value
     * @return string
     */
    public static function format_value(string $field, $value): string {
        $component = 'local_chronifycompliance';
        if ($field === 'completionstatusrequired') {
            return get_string('scorm:status:' . (int) $value, $component);
        }
        if ($field === 'forcenewattempt') {
            return get_string('scorm:forcenewattempt:' . (int) $value, $component);
        }
        if ($field === 'completionscorerequired') {
            return $value === null ? get_string('none') : (string) $value;
        }
        return $value ? get_string('yes') : get_string('no');
    }

    /**
     * Fixable when recommended values were stored.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['recommended']) && !empty($finding->details['scormid']);
    }

    /**
     * Preview applying the recommended values.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        global $DB;
        $recommended = array_intersect_key($finding->details['recommended'], array_flip(self::FIXABLE_FIELDS));
        $scorm = $DB->get_record(
            'scorm',
            ['id' => $finding->details['scormid']],
            'id, ' . implode(', ', self::FIXABLE_FIELDS),
            MUST_EXIST
        );
        $before = ['scormid' => (int) $scorm->id, 'cmid' => (int) $finding->cmid];
        $after = ['scormid' => (int) $scorm->id, 'cmid' => (int) $finding->cmid];
        $preview = new fix_preview('scormsettings', [], []);
        foreach ($recommended as $field => $value) {
            $current = $scorm->$field === null ? null : (int) $scorm->$field;
            if ($current === $value) {
                continue;
            }
            $before[$field] = $current;
            $after[$field] = $value;
            $preview->add_row(
                get_string('scorm:field:' . $field, 'local_chronifycompliance'),
                self::format_value($field, $current),
                self::format_value($field, $value)
            );
        }
        $preview->before = $before;
        $preview->after = $after;
        $preview->add_warning(get_string('fix:warning:norecalculation', 'local_chronifycompliance'));
        return $preview;
    }

    /**
     * Write the recommended values.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $this->write_settings($preview->after);
        return new fix_result(true, $preview->after);
    }

    /**
     * Restore the previous values.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        global $DB;
        $fields = array_values(array_intersect(self::FIXABLE_FIELDS, array_keys($after)));
        if ($fields) {
            $current = (array) $DB->get_record('scorm', ['id' => $after['scormid']], implode(', ', $fields), MUST_EXIST);
            $current = array_map(fn($value) => $value === null ? null : (int) $value, $current);
            $this->assert_unchanged($current, array_intersect_key($after, array_flip($fields)));
        }
        $before += ['cmid' => (int) $fixlog->cmid];
        $this->write_settings($before);
        return new fix_result(true, $before);
    }

    /**
     * Update scorm fields.
     *
     * @param array $values scormid plus fields.
     */
    private function write_settings(array $values): void {
        global $DB;
        $fields = array_intersect_key($values, array_flip(self::FIXABLE_FIELDS));
        $cmid = (int) ($values['cmid'] ?? 0)
            ?: (int) $DB->get_field_sql("SELECT cm.id FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
                                          WHERE m.name = 'scorm' AND cm.instance = :id", ['id' => $values['scormid']]);
        activity_completion::update($cmid, [], $fields);
    }
}
