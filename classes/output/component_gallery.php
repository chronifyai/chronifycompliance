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

use html_writer;
use moodle_url;

/**
 * Component gallery: every shared component in each of its states, for checking the design.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class component_gallery {
    /**
     * HTML of the gallery.
     *
     * @param \renderer_base $output
     * @return string
     */
    public static function render(\renderer_base $output): string {
        $component = 'local_chronifycompliance';
        $html = html_writer::tag('p', get_string('components:intro', $component));
        // Heading of one state.
        $state = function (string $state) use ($component): string {
            $text = get_string('components:state', $component, $state);
            return html_writer::tag('p', $text, ['class' => 'small fw-bold mt-3 mb-1']);
        };

        // Top navigation.
        $html .= $output->heading(get_string('components:nav', $component), 3);
        $html .= $output->render(new workspace_nav('dashboard'));

        // Data age line. The live state of the activity dataset, then each state with sample data.
        $html .= $output->heading(get_string('components:dataage', $component), 3);
        $html .= $state('live');
        $canrefresh = has_capability('local/chronifycompliance:runscan', \context_system::instance());
        $html .= $output->render(new data_age('activity', null, $canrefresh));
        $base = ['dataset' => 'sample', 'hastime' => true, 'time' => data_age::format_time(time() - HOURSECS),
            'refreshing' => false, 'failed' => false, 'canrefresh' => false];
        $samples = [
            'fresh' => [],
            'refreshing' => ['refreshing' => true],
            'failed' => ['failed' => true],
            'never' => ['hastime' => false, 'time' => null],
            'yesterday' => ['time' => data_age::format_time(usergetmidnight(time()) - 2 * HOURSECS)],
        ];
        foreach ($samples as $name => $changes) {
            $html .= $state($name);
            $html .= $output->render_from_template("{$component}/data_age", array_merge($base, $changes));
        }

        // Finding card.
        $html .= $output->heading(get_string('components:finding', $component), 3);
        $finding = [
            'id' => 0, 'checkname' => get_string('check:nopassgrade:name', $component),
            'checkdesc' => get_string('check:nopassgrade:desc', $component),
            'message' => 'Quiz "Final test" requires a passing grade, but its grade to pass is 0.',
            'severitylabel' => get_string('severity:critical', $component), 'badgeclass' => 'lcd-pill lcd-pill-critical',
            'cmname' => 'Final test', 'cmurl' => null, 'editurl' => null, 'scormurl' => null,
            'canfix' => true, 'canignore' => true, 'canreopen' => false, 'fixlogid' => null, 'ignorereason' => null,
        ];
        $findings = [
            'open' => [],
            'fixed' => ['canfix' => false, 'canignore' => false, 'fixlogid' => 1,
                'severitylabel' => get_string('severity:info', $component), 'badgeclass' => 'lcd-pill lcd-pill-info'],
            'ignored' => ['canfix' => false, 'canignore' => false, 'canreopen' => true, 'ignorereason' => 'Practice quiz'],
        ];
        foreach ($findings as $name => $changes) {
            $html .= $state($name);
            $html .= $output->render_from_template("{$component}/finding", array_merge($finding, $changes));
        }

        // Invitation. The real one for the dashboard, so dismissing it works.
        $html .= $output->heading(get_string('components:invitation', $component), 3);
        $html .= $state('live');
        $invite = invitation::for_page('dashboard', new moodle_url('https://chronifyai.com'));
        $html .= html_writer::div(
            $invite ? $output->render($invite) : html_writer::tag('p', get_string('none'), ['class' => 'text-muted']),
            '',
            ['data-region' => 'invitation-slot']
        );

        // Undo toast.
        $html .= $output->heading(get_string('components:toast', $component), 3);
        $html .= $output->render_from_template("{$component}/undo_toast", ['fixlogid' => 0, 'inline' => true,
            'message' => 'Grade to pass set to 7 out of 10.']);
        return $html;
    }
}
