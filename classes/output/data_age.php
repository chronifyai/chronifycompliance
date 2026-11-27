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

use local_chronifycompliance\data\refresher;
use renderer_base;

/**
 * The line under a page title saying how fresh its data is, with Refresh now.
 *
 * States: fresh (a time), refreshing (a refresh is queued or running), never (no data yet), and failed (the
 * last refresh failed; the page keeps showing the last good data).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_age implements \core\output\named_templatable, \renderable {
    /**
     * Constructor.
     *
     * @param string|null $datasetid Dataset the page reads, for its state and Refresh now; null for a plain time.
     * @param int|null $time Time of the data when there is no dataset (for example the last scan).
     * @param bool $canrefresh Whether the user may ask for a refresh.
     */
    public function __construct(
        /** @var string|null Dataset id. */
        private ?string $datasetid,
        /** @var int|null Time of the data without a dataset. */
        private ?int $time = null,
        /** @var bool Whether Refresh now is offered. */
        private bool $canrefresh = false
    ) {
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/data_age';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $time = $this->time;
        $refreshing = false;
        $failed = false;
        if ($this->datasetid !== null) {
            $state = refresher::get_state($this->datasetid);
            $time = $state && $state->timefinished ? (int) $state->timefinished : null;
            $refreshing = ($state && $state->status === refresher::STATUS_RUNNING) || refresher::is_requested($this->datasetid);
            $failed = $state && $state->status === refresher::STATUS_FAILED;
        }
        return [
            'dataset' => $this->datasetid,
            'hastime' => $time !== null,
            'time' => $time !== null ? self::format_time($time) : null,
            'refreshing' => $refreshing,
            'failed' => $failed,
            'canrefresh' => $this->canrefresh && $this->datasetid !== null && !$refreshing,
        ];
    }

    /**
     * "2:14 AM today", "2:14 AM yesterday" or a date with time.
     *
     * @param int $time
     * @return string
     */
    public static function format_time(int $time): string {
        $clock = userdate($time, get_string('strftimetime', 'langconfig'));
        $midnight = usergetmidnight(time());
        if ($time >= $midnight) {
            return get_string('dataage:today', 'local_chronifycompliance', $clock);
        }
        if ($time >= $midnight - DAYSECS) {
            return get_string('dataage:yesterday', 'local_chronifycompliance', $clock);
        }
        return userdate($time, get_string('strftimedatetimeshort', 'langconfig'));
    }
}
