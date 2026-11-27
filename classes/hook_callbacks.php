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

namespace local_chronifycompliance;

use core\hook\output\before_http_headers;
use local_chronifycompliance\local\finding_store;

/**
 * Hook callbacks.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Page types showing the banner. */
    private const BANNER_PAGES = ['course-view-', 'course-completion'];

    /**
     * Warn teachers on the course page and the course completion settings page when the course has
     * open critical findings. Uses the cached summary only; never audits. The warning is a standard
     * notification, so every theme shows it in the main content area.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;
        if (during_initial_install() || !get_config('local_chronifycompliance', 'showbanner')) {
            return;
        }
        $course = $PAGE->course ?? null;
        if (!$course || (int) $course->id === (int) SITEID || empty($course->enablecompletion)) {
            return;
        }
        $matches = array_filter(self::BANNER_PAGES, fn($prefix) => str_starts_with((string) $PAGE->pagetype, $prefix));
        if (!$matches) {
            return;
        }
        $context = \context_course::instance($course->id);
        if (!has_capability('local/chronifycompliance:viewcoursereport', $context)) {
            return;
        }
        $critical = finding_store::get_summary((int) $course->id)['critical'];
        if (!$critical) {
            return;
        }
        $link = \html_writer::link(
            new \moodle_url('/local/chronifycompliance/course.php', ['id' => $course->id]),
            get_string('banner:view', 'local_chronifycompliance')
        );
        \core\notification::add(
            get_string('banner:message', 'local_chronifycompliance', $critical) . ' ' . $link,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}
