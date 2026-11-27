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

namespace local_completiondoctor\scorm;

/**
 * Compares the fixed stage size of a package with how the activity displays it (static part only).
 *
 * The SCORM player embeds the package in a frame whose height is the activity height, and the
 * course table of contents on the side takes width away. A pop-up uses the activity width and height.
 * Sizes of 100 or less are percentages in mod_scorm and cannot be judged statically.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stage_detector {
    /** @var int Width the side table of contents typically takes, in pixels. */
    public const SIDE_TOC_WIDTH = 250;
    /** @var int Stage widths at or above this are affected by a side table of contents. */
    private const WIDE_STAGE = 720;

    /**
     * Fit problems.
     *
     * @param array $stage Package stage: width, height, fluid.
     * @param \stdClass $scorm scorm record.
     * @return array[] Language string references with parameters.
     */
    public static function get_issues(array $stage, \stdClass $scorm): array {
        if (!empty($stage['fluid']) || empty($stage['width']) || empty($stage['height'])) {
            return [];
        }
        $width = (int) $stage['width'];
        $height = (int) $stage['height'];
        $framewidth = (int) $scorm->width;
        $frameheight = (int) $scorm->height;
        $size = fn(int $value): string => $value <= 100 ? "{$value}%" : (string) $value;
        $a = ['stage' => "{$width}×{$height}", 'frame' => $size($framewidth) . '×' . $size($frameheight)];
        $issues = [];

        if ((int) $scorm->popup === 1) {
            // Some sites open pop-ups as full browser tabs (setting "popupfulltab"): no size to judge then.
            if (get_config('local_completiondoctor', 'popupfulltab')) {
                return [];
            }
            if (($framewidth > 100 && $framewidth < $width) || ($frameheight > 100 && $frameheight < $height)) {
                $issues[] = ['str' => 'stage:popupsmall', 'a' => $a];
            }
            return $issues;
        }
        if ($frameheight > 100 && $frameheight < $height) {
            $issues[] = ['str' => 'stage:frameshort', 'a' => $a];
        }
        if ((int) $scorm->hidetoc === 0 && $width >= self::WIDE_STAGE) {
            $issues[] = ['str' => 'stage:sidetoc', 'a' => $a + ['toc' => self::SIDE_TOC_WIDTH]];
        }
        return $issues;
    }
}
