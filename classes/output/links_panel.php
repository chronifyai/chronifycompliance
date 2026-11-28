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

use local_chronifycompliance\links\link_checker;
use local_chronifycompliance\links\link_scan;
use local_chronifycompliance\links\link_store;
use moodle_url;
use renderer_base;

/**
 * Links panel of the course report: state of the last link check and the broken links.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class links_panel implements \core\output\named_templatable, \renderable {
    /** @var int Most links of one status listed on the course report. */
    private const MAX_LINKS_LISTED = 100;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \course_modinfo Activities of the course. */
    private \course_modinfo $modinfo;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     */
    public function __construct(\stdClass $course, \course_modinfo $modinfo) {
        $this->course = $course;
        $this->modinfo = $modinfo;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/links_panel';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        if (!link_scan::is_enabled()) {
            return [];
        }
        $component = 'local_chronifycompliance';
        $courseid = (int) $this->course->id;
        $state = link_scan::get_state($courseid);
        $status = $state->status ?? '';
        $runat = $status === 'queued' && !(int) $state->requestedby ? (int) $state->runat : 0;
        $runat = $runat > time() ? $runat : 0;
        $data = [
            'cancheck' => has_capability('local/chronifycompliance:checklinks', \context_course::instance($courseid)),
            'checkurl' => (new moodle_url('/local/chronifycompliance/course.php', ['id' => $courseid]))->out(false),
            'refreshurl' => (new moodle_url(
                '/local/chronifycompliance/course.php',
                ['id' => $courseid, 'tab' => 'links']
            ))->out(false),
            'sesskey' => sesskey(),
            'never' => !$state,
            'starting' => $status === 'queued' && !$runat,
            'scheduled' => $runat ? get_string('links:scheduled', $component, userdate($runat)) : null,
            'running' => $status === 'running',
            // A waiting automatic check can be started now with the button.
            'busy' => $status === 'running' || ($status === 'queued' && !$runat),
            'progress' => $status === 'running'
                ? get_string('links:running', $component, ['done' => (int) $state->processed, 'total' => (int) $state->totalitems])
                : null,
            'failed' => $status === link_scan::STATUS_FAILED
                ? get_string('links:failed', $component, userdate((int) $state->timefinished)) : null,
            'groups' => [],
        ];
        $counts = link_store::count_by_status($courseid);
        $total = array_sum($counts);
        $finished = link_scan::get_last_finished($courseid);
        if ($finished) {
            $data['checked'] = get_string('links:summary', $component, [
                'time' => userdate((int) $finished->timefinished),
                'total' => $total,
                'broken' => $counts[link_checker::STATUS_BROKEN],
                'unverified' => $counts[link_checker::STATUS_UNVERIFIED],
            ]);
            $data['nobroken'] = $counts[link_checker::STATUS_BROKEN] === 0;
            $data['skipped'] = $counts[link_checker::STATUS_SKIPPED]
                ? get_string('links:skipped', $component, $counts[link_checker::STATUS_SKIPPED]) : null;
            $data['skippedfiles'] = $finished->skippedfiles
                ? get_string('links:skippedfiles', $component, (int) $finished->skippedfiles) : null;
            $data['truncated'] = (bool) $finished->truncated;
        }
        foreach ([link_checker::STATUS_BROKEN => 'broken', link_checker::STATUS_UNVERIFIED => 'unverified'] as $value => $name) {
            if (!$counts[$value]) {
                continue;
            }
            $links = link_store::get_course_links($courseid, [$value], self::MAX_LINKS_LISTED);
            $more = $counts[$value] - count($links);
            $data['groups'][] = [
                'name' => $name,
                'label' => get_string('links:' . $name, $component),
                'pillclass' => $value === link_checker::STATUS_BROKEN ? 'lcd-pill lcd-pill-critical' : 'lcd-pill lcd-pill-info',
                'count' => $counts[$value],
                'help' => $value === link_checker::STATUS_UNVERIFIED ? get_string('links:unverified_help', $component) : null,
                'locations' => $this->export_link_locations($links),
                'more' => $more > 0 ? get_string('links:more', $component, $more) : null,
            ];
        }
        $data['hasgroups'] = (bool) $data['groups'];
        return $data;
    }

    /**
     * Links grouped by where they are (course summary, section, activity), in course order.
     *
     * @param \stdClass[] $links Link records.
     * @return array[]
     */
    private function export_link_locations(array $links): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $component = 'local_chronifycompliance';
        $cms = $this->modinfo->get_cms();
        $cmorder = array_flip(array_keys($cms));
        $sections = [];
        foreach ($this->modinfo->get_section_info_all() as $section) {
            $sections[(int) $section->id] = $section;
        }

        $locations = [];
        foreach ($links as $link) {
            $cm = (int) $link->cmid ? ($cms[(int) $link->cmid] ?? null) : null;
            if ($cm) {
                $key = 'cm' . $cm->id;
                $location = [
                    'order' => 2000000 + $cmorder[$cm->id],
                    'icon' => $cm->get_icon_url()->out(false),
                    'type' => get_string('modulename', $cm->modname),
                    'name' => $cm->get_formatted_name(),
                    'section' => get_string('links:location:section', $component, get_section_name($this->course, $cm->sectionnum)),
                    'openurl' => $cm->url ? $cm->url->out(false) : null,
                    'editurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
                ];
            } else if ($link->source === 'section' && isset($sections[(int) $link->itemid])) {
                $section = $sections[(int) $link->itemid];
                $key = 'section' . $section->id;
                $location = [
                    'order' => 1000000 + (int) $section->section,
                    'name' => get_string('links:location:section', $component, get_section_name($this->course, $section)),
                    'openurl' => course_get_url($this->course, (int) $section->section)->out(false),
                    'editurl' => (new moodle_url('/course/editsection.php', ['id' => $section->id]))->out(false),
                ];
            } else if ($link->source === 'summary') {
                $key = 'course';
                $location = [
                    'order' => 0,
                    'name' => get_string('links:location:course', $component),
                    'openurl' => course_get_url($this->course)->out(false),
                    'editurl' => (new moodle_url('/course/edit.php', ['id' => $this->course->id]))->out(false),
                ];
            } else {
                $key = 'other';
                $location = [
                    'order' => 3000000,
                    'name' => get_string(
                        $link->source === 'question' ? 'links:location:questionbank' : 'links:location:other',
                        $component
                    ),
                ];
            }
            $locations[$key] ??= $location + ['icon' => null, 'type' => null, 'section' => null, 'openurl' => null,
                'editurl' => null, 'links' => []];
            $locations[$key]['links'][] = $this->export_link($link);
        }
        uasort($locations, fn($a, $b) => $a['order'] <=> $b['order']);
        foreach ($locations as $key => $location) {
            $locations[$key]['count'] = count($location['links']);
            unset($locations[$key]['order']);
        }
        return array_values($locations);
    }

    /**
     * One link of the links panel.
     *
     * @param \stdClass $link Link record.
     * @return array
     */
    private function export_link(\stdClass $link): array {
        $component = 'local_chronifycompliance';
        $url = (string) $link->url;
        $href = null;
        if (str_starts_with($url, '@@PLUGINFILE@@/')) {
            $display = get_string('links:embedded', $component, rawurldecode(basename((string) strtok($url, '?'))));
        } else {
            $display = shorten_text($url, 90, true);
            $href = str_starts_with($url, '/') ? (new moodle_url($url))->out(false) : $url;
        }
        $options = ['context' => \context_course::instance($this->course->id)];
        $sourcename = $link->sourcename === null ? '' : format_string($link->sourcename, true, $options);
        return [
            'display' => $display,
            'href' => $href,
            'linktext' => ($link->linktext ?? '') !== ''
                ? get_string('links:linktext', $component, format_string($link->linktext, true, $options)) : null,
            'where' => in_array($link->source, ['summary', 'section'], true) ? null
                : get_string('links:where', $component, get_string('links:source:' . $link->source, $component, $sourcename)),
            'reason' => $link->reason ? get_string('links:reason:' . $link->reason, $component, (int) $link->httpcode) : null,
        ];
    }
}
