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

use moodle_url;
use renderer_base;

/**
 * Top navigation of the admin pages: Dashboard, Completion, Security and Compliance, Reports, Data and refresh.
 *
 * Lists only the pages that exist in this version, so the bar grows as workspaces are added.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workspace_nav implements \core\output\named_templatable, \renderable {
    /** @var string[] Page key => script, in display order. */
    public const PAGES = [
        'dashboard' => 'index.php',
        'completion' => 'completion.php',
        'security' => 'security.php',
        'reports' => 'reports.php',
        'data' => 'data.php',
    ];

    /**
     * Constructor.
     *
     * @param string $active Key of the current page.
     */
    public function __construct(
        /** @var string Key of the current page. */
        private string $active
    ) {
    }

    /**
     * Pages that exist, with their address.
     *
     * @return moodle_url[] Keyed by page key.
     */
    public static function get_pages(): array {
        global $CFG;
        $pages = [];
        foreach (self::PAGES as $key => $script) {
            if (file_exists($CFG->dirroot . '/local/chronifycompliance/' . $script)) {
                $pages[$key] = new moodle_url('/local/chronifycompliance/' . $script);
            }
        }
        return $pages;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/workspace_nav';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $items = [];
        foreach (self::get_pages() as $key => $url) {
            $items[] = [
                'key' => $key,
                'label' => get_string('nav:' . $key, 'local_chronifycompliance'),
                'url' => $url->out(false),
                'active' => $key === $this->active,
            ];
        }
        return ['items' => $items];
    }
}
