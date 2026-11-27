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

/**
 * Renderer of the Chronify.Compliance pages. Each page is a renderable in this namespace with its own
 * template; this class only keeps the entry points the pages call.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Course report.
     *
     * @param \stdClass $course
     * @param string $tab Open tab, see course_report::TABS.
     * @return string
     */
    public function course_report(\stdClass $course, string $tab = 'problems'): string {
        return $this->render(new course_report($course, $tab));
    }

    /**
     * Dashboard header.
     *
     * @param string $view Selected health group, or ''.
     * @param string $checkid Selected check, or ''.
     * @return string
     */
    public function dashboard_header(string $view, string $checkid): string {
        return $this->render(new dashboard_header($view, $checkid));
    }

    /**
     * SCORM panel.
     *
     * @param \cm_info $cm
     * @param array $inspection See inspector::inspect().
     * @return string
     */
    public function scorm_panel(\cm_info $cm, array $inspection): string {
        return $this->render(new scorm_panel($cm, $inspection));
    }

    /**
     * Learner diagnostic.
     *
     * @param \stdClass $course
     * @param \stdClass|null $user Selected learner, or null.
     * @param array|null $explanation See explainer::explain().
     * @param array $matches Users matching the search.
     * @param string $search
     * @return string
     */
    public function learner_report(
        \stdClass $course,
        ?\stdClass $user,
        ?array $explanation,
        array $matches,
        string $search
    ): string {
        return $this->render(new learner_report($course, $user, $explanation, $matches, $search));
    }
}
