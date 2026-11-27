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

namespace local_chronifycompliance\output;

use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fix_log;
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use local_chronifycompliance\scorm\package_analyzer;
use moodle_url;
use renderer_base;

/**
 * Course completion health report of one course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_report implements \core\output\named_templatable, \renderable {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \course_modinfo Activities of the course. */
    private \course_modinfo $modinfo;
    /** @var string Open tab: problems, ignored, fixed or links. */
    private string $tab;
    /** @var string[] Tabs. */
    public const TABS = ['problems', 'ignored', 'fixed', 'links'];
    /** @var int Fix history entries shown. */
    public const HISTORY = 50;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param string $tab One of TABS.
     */
    public function __construct(\stdClass $course, string $tab = 'problems') {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'problems';
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/course_report';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;
        $component = 'local_chronifycompliance';
        $context = \context_course::instance($this->course->id);
        $caps = [
            'canfix' => has_capability('local/chronifycompliance:applyfix', $context),
            'canignore' => has_capability('local/chronifycompliance:ignorefinding', $context),
        ];
        $courseid = (int) $this->course->id;
        $url = new moodle_url('/local/chronifycompliance/course.php', ['id' => $courseid]);
        $counts = ['problems' => 0, 'ignored' => 0, 'fixed' => $DB->count_records(fix_log::TABLE, ['courseid' => $courseid])];
        $groups = [];
        foreach (finding_exporter::SEVERITIES as $severity => $name) {
            $groups[$severity] = ['severity' => $name, 'label' => get_string('severity:' . $name, $component),
                'badgeclass' => finding_exporter::BADGES[$name], 'findings' => []];
        }
        $ignored = [];
        foreach (finding_store::get_course_findings($courseid, null) as $finding) {
            if ($finding->status === finding::STATUS_OPEN) {
                $counts['problems']++;
                if ($this->tab === 'problems') {
                    $groups[$finding->severity]['findings'][] = finding_exporter::export($finding, $this->modinfo, $caps);
                }
            } else if ($finding->status === finding::STATUS_IGNORED) {
                $counts['ignored']++;
                if ($this->tab === 'ignored') {
                    $ignored[] = finding_exporter::export($finding, $this->modinfo, $caps);
                }
            }
        }
        foreach ($groups as $severity => $group) {
            $groups[$severity]['count'] = count($group['findings']);
        }
        $groups = array_values(array_filter($groups, fn($g) => $g['count'] > 0));

        $tabs = [];
        foreach (self::TABS as $tab) {
            $label = get_string('report:tab:' . $tab, $component);
            $tabs[] = [
                'key' => $tab,
                'label' => isset($counts[$tab]) ? get_string('report:tabcount', $component, ['tab' => $label,
                    'count' => $counts[$tab]]) : $label,
                'url' => (new moodle_url($url, ['tab' => $tab]))->out(false),
                'active' => $tab === $this->tab,
            ];
        }
        $audit = audit_store::get($courseid);

        $data = [
            'courseid' => $courseid,
            'tab' => $this->tab,
            'tabs' => $tabs,
            'isproblems' => $this->tab === 'problems',
            'isignored' => $this->tab === 'ignored',
            'isfixed' => $this->tab === 'fixed',
            'islinks' => $this->tab === 'links',
            'checking' => audit_store::get_requested($courseid) > 0,
            'checked' => $audit ? get_string(
                'report:checkedat',
                $component,
                data_age::format_time((int) $audit->timeaudited)
            ) : null,
            'summary' => self::summary($courseid),
            'recheckurl' => (new moodle_url($url, ['tab' => $this->tab]))->out(false),
            'sesskey' => sesskey(),
            'groups' => $groups,
            'hasopen' => (bool) $groups,
            'ignored' => $ignored,
            'hasignored' => (bool) $ignored,
            'history' => $this->tab === 'fixed' ? $this->history($caps['canfix']) : [],
            'learnerurl' => has_capability('local/chronifycompliance:viewlearnerdiagnostic', $context)
                ? (new moodle_url('/local/chronifycompliance/learner.php', ['courseid' => $courseid]))->out(false) : null,
            'completionsettingsurl' => (new moodle_url('/course/completion.php', ['id' => $courseid]))->out(false),
            'links' => $this->tab === 'links'
                ? (new links_panel($this->course, $this->modinfo))->export_for_template($output) : null,
            'scormwaiting' => $this->tab === 'problems' ? $this->waiting_packages() : null,
        ];
        $data['hashistory'] = (bool) $data['history'];
        return $data;
    }

    /**
     * Fix history of the course, newest first. Undo is offered for the latest fix of each finding that is
     * not undone yet.
     *
     * @param bool $canfix Whether the user may undo.
     * @return array
     */
    private function history(bool $canfix): array {
        global $DB;
        $component = 'local_chronifycompliance';
        $records = $DB->get_records(fix_log::TABLE, ['courseid' => $this->course->id], 'id DESC', '*', 0, self::HISTORY);
        $userids = [];
        foreach ($records as $record) {
            $userids[] = (int) $record->userid;
            $userids[] = (int) $record->undoneby;
        }
        $users = $userids ? $DB->get_records_list(
            'user',
            'id',
            array_unique(array_filter($userids)),
            '',
            implode(',', \core_user\fields::get_name_fields()) . ', id'
        ) : [];
        $name = fn(int $userid) => isset($users[$userid]) ? fullname($users[$userid]) : get_string('unknownuser');
        $cms = $this->modinfo->get_cms();
        $latest = [];
        $rows = [];
        foreach ($records as $record) {
            $check = check_registry::get_check($record->checkid);
            $undoable = $canfix && !$record->undone && !isset($latest[$record->findingid]);
            if (!$record->undone) {
                $latest[$record->findingid] = true;
            }
            $rows[] = [
                'fixlogid' => (int) $record->id,
                'checkname' => $check ? $check->get_name() : $record->checkid,
                'cmname' => $record->cmid && isset($cms[$record->cmid]) ? $cms[$record->cmid]->get_formatted_name() : null,
                'applied' => get_string('report:history:applied', $component, ['time' => userdate(
                    (int) $record->timecreated,
                    get_string('strftimedatetimeshort', 'langconfig')
                ), 'user' => $name((int) $record->userid)]),
                'undone' => $record->undone ? get_string('report:history:undone', $component, ['time' => userdate(
                    (int) $record->timeundone,
                    get_string('strftimedatetimeshort', 'langconfig')
                ),
                    'user' => $name((int) $record->undoneby)]) : null,
                'canundo' => $undoable,
            ];
        }
        return $rows;
    }

    /**
     * Counts of open findings and the last audit time.
     *
     * @param int $courseid
     * @return array
     */
    public static function summary(int $courseid): array {
        $summary = finding_store::get_summary($courseid);
        $audit = audit_store::get($courseid);
        return [
            'critical' => $summary['critical'],
            'warning' => $summary['warning'],
            'info' => $summary['info'],
            'total' => $summary['total'],
            'healthy' => $audit && !$summary['total'],
            'neveraudited' => !$audit,
            'lastaudit' => $audit ? userdate((int) $audit->timeaudited) : null,
            'checkerrors' => $audit && $audit->checkerrors
                ? implode(', ', array_keys(json_decode($audit->checkerrors, true))) : null,
        ];
    }

    /**
     * SCORM activities whose package is not analyzed yet, so their SCORM checks have not run.
     *
     * @return array|null Null when every package is analyzed.
     */
    private function waiting_packages(): ?array {
        $cms = $this->modinfo->get_cms();
        $items = [];
        foreach (package_analyzer::get_waiting((int) $this->course->id) as $record) {
            if (isset($cms[$record->cmid])) {
                $items[] = [
                    'cmid' => (int) $record->cmid,
                    'name' => $cms[$record->cmid]->get_formatted_name(),
                    'size' => display_size((int) $record->filesize),
                ];
            }
        }
        if (!$items) {
            return null;
        }
        return [
            'message' => get_string('report:scormwaiting', 'local_chronifycompliance', count($items)),
            'items' => $items,
            'actionurl' => (new moodle_url('/local/chronifycompliance/course.php', ['id' => $this->course->id]))->out(false),
        ];
    }
}
