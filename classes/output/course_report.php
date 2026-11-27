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

namespace local_completiondoctor\output;

use local_completiondoctor\check\finding;
use local_completiondoctor\local\audit_store;
use local_completiondoctor\local\finding_store;
use local_completiondoctor\scorm\package_analyzer;
use moodle_url;
use renderer_base;

/**
 * Course completion health report of one course.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_report implements \core\output\named_templatable, \renderable {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \course_modinfo Activities of the course. */
    private \course_modinfo $modinfo;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_completiondoctor/course_report';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $context = \context_course::instance($this->course->id);
        $caps = [
            'canfix' => has_capability('local/completiondoctor:applyfix', $context),
            'canignore' => has_capability('local/completiondoctor:ignorefinding', $context),
        ];
        $all = finding_store::get_course_findings((int) $this->course->id, null);
        $groups = [];
        foreach (finding_exporter::SEVERITIES as $severity => $name) {
            $groups[$severity] = ['severity' => $name, 'label' => get_string('severity:' . $name, 'local_completiondoctor'),
                'badgeclass' => finding_exporter::BADGES[$name], 'findings' => []];
        }
        $ignored = [];
        $fixed = [];
        $modinfo = $this->modinfo;
        foreach ($all as $finding) {
            $item = finding_exporter::export($finding, $modinfo, $caps);
            if ($finding->status === finding::STATUS_OPEN) {
                $groups[$finding->severity]['findings'][] = $item;
            } else if ($finding->status === finding::STATUS_IGNORED) {
                $ignored[] = $item;
            } else if ($finding->status === finding::STATUS_FIXED) {
                $fixed[] = $item;
            }
        }
        foreach ($groups as $severity => $group) {
            $groups[$severity]['count'] = count($group['findings']);
            $groups[$severity]['hasfindings'] = $groups[$severity]['count'] > 0;
        }

        $data = [
            'courseid' => (int) $this->course->id,
            'checking' => audit_store::get_requested((int) $this->course->id) > 0,
            'summary' => self::summary((int) $this->course->id),
            'recheckurl' => (new moodle_url('/local/completiondoctor/course.php', ['id' => $this->course->id]))->out(false),
            'sesskey' => sesskey(),
            'groups' => array_values(array_filter($groups, fn($g) => $g['hasfindings'])),
            'hasopen' => (bool) array_filter($groups, fn($g) => $g['hasfindings']),
            'ignored' => $ignored,
            'hasignored' => (bool) $ignored,
            'fixed' => $fixed,
            'hasfixed' => (bool) $fixed,
            'fixedcount' => count($fixed),
            'learnerurl' => has_capability('local/completiondoctor:viewlearnerdiagnostic', $context)
                ? (new moodle_url('/local/completiondoctor/learner.php', ['courseid' => $this->course->id]))->out(false) : null,
            'completionsettingsurl' => (new moodle_url('/course/completion.php', ['id' => $this->course->id]))->out(false),
            'links' => (new links_panel($this->course, $modinfo))->export_for_template($output),
            'scormwaiting' => $this->waiting_packages(),
        ];
        return $data;
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
            'message' => get_string('report:scormwaiting', 'local_completiondoctor', count($items)),
            'items' => $items,
            'actionurl' => (new moodle_url('/local/completiondoctor/course.php', ['id' => $this->course->id]))->out(false),
        ];
    }
}
