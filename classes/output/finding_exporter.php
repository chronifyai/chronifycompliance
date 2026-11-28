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

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\fix\fix_log;
use moodle_url;

/**
 * Exports findings, for templates and for web services.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding_exporter {
    /** @var string[] Severity names by value. */
    public const SEVERITIES = [
        base_check::SEVERITY_CRITICAL => 'critical',
        base_check::SEVERITY_WARNING => 'warning',
        base_check::SEVERITY_INFO => 'info',
    ];
    /** @var string[] Pill classes by severity (styles.css). */
    public const BADGES = [
        'critical' => 'lcd-pill lcd-pill-critical',
        'warning' => 'lcd-pill lcd-pill-warning',
        'info' => 'lcd-pill lcd-pill-info',
    ];

    /**
     * A finding prepared for templates.
     *
     * @param finding $finding
     * @param \course_modinfo|null $modinfo
     * @param array $caps Keys canfix and canignore.
     * @return array
     */
    public static function export(finding $finding, ?\course_modinfo $modinfo, array $caps): array {
        $check = check_registry::get_check($finding->checkid);
        $severity = self::SEVERITIES[$finding->severity] ?? 'info';
        $item = [
            'id' => (int) $finding->id,
            'checkid' => $finding->checkid,
            'checkname' => $check ? $check->get_name() : $finding->checkid,
            'checkdesc' => $check ? $check->get_description() : '',
            'message' => $finding->get_message(),
            'severitylabel' => get_string('severity:' . $severity, 'local_chronifycompliance'),
            'badgeclass' => self::BADGES[$severity],
            'canfix' => $caps['canfix'] && $finding->fixable && $finding->is_open(),
            'canignore' => $caps['canignore'] && $finding->is_open(),
            'canreopen' => $caps['canignore'] && $finding->status === finding::STATUS_IGNORED,
            'ignorereason' => $finding->ignorereason,
            'timeignored' => $finding->timeignored ? userdate($finding->timeignored) : null,
            'cmname' => null,
            'cmurl' => null,
            'editurl' => null,
            'scormurl' => null,
            'fixlogid' => null,
        ];
        if ($finding->cmid && $modinfo) {
            $cms = $modinfo->get_cms();
            if (isset($cms[$finding->cmid])) {
                $cm = $cms[$finding->cmid];
                $item['cmname'] = $cm->get_formatted_name();
                $item['cmurl'] = $cm->url ? $cm->url->out(false) : null;
                $item['editurl'] = (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false);
                if ($cm->modname === 'scorm') {
                    $item['scormurl'] = (new moodle_url('/local/chronifycompliance/scorm.php', ['cmid' => $cm->id]))->out(false);
                }
            }
        }
        // Site findings can point at the Moodle setting that fixes them.
        if (!$item['editurl'] && !empty($finding->details['url'])) {
            $item['editurl'] = (string) $finding->details['url'];
        }
        if ($finding->status === finding::STATUS_FIXED && $caps['canfix']) {
            $log = fix_log::get_latest_active((int) $finding->id);
            $item['fixlogid'] = $log ? (int) $log->id : null;
        }
        return self::add_main_action($item);
    }

    /**
     * One main action per card, by fix level: Preview fix, Undo, Reopen, Set up (activity settings) or the
     * SCORM analysis. The other actions go in the card's menu.
     *
     * @param array $item
     * @return array
     */
    public static function add_main_action(array $item): array {
        $main = null;
        if ($item['canfix']) {
            $main = 'fix';
        } else if ($item['fixlogid']) {
            $main = 'undo';
        } else if ($item['canreopen']) {
            $main = 'reopen';
        } else if ($item['scormurl']) {
            $main = 'scorm';
        } else if ($item['editurl']) {
            $main = 'setup';
        }
        foreach (['fix', 'undo', 'reopen', 'scorm', 'setup'] as $action) {
            $item['main' . $action] = $main === $action;
        }
        $item['menuedit'] = $item['editurl'] && $main !== 'setup';
        $item['menuscorm'] = $item['scormurl'] && $main !== 'scorm';
        return $item;
    }

    /**
     * A finding for web services.
     *
     * @param finding $finding
     * @return array
     */
    public static function export_for_api(finding $finding): array {
        $check = check_registry::get_check($finding->checkid);
        return [
            'id' => (int) $finding->id,
            'courseid' => $finding->courseid,
            'cmid' => $finding->cmid,
            'checkid' => $finding->checkid,
            'checkname' => $check ? $check->get_name() : $finding->checkid,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'message' => $finding->get_message(),
            'fixable' => $finding->fixable,
            'details' => json_encode($finding->details),
            'timecreated' => $finding->timecreated,
            'lastseen' => $finding->lastseen,
        ];
    }
}
