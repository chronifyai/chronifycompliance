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

namespace local_chronifycompliance\scorm;

/**
 * Everything known about one SCORM activity: package analysis (cached), runtime evidence,
 * recommendation and stage fit.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inspector {
    /** @var string[] SCORM types with a package file in Moodle. */
    private const PACKAGE_TYPES = ['local', 'localsync'];
    /** @var string Stored analysis only, never analyze (page views). */
    public const MODE_READ = 'read';
    /** @var string Audit: analyze small packages now, queue the others (stored). */
    public const MODE_AUDIT = 'audit';
    /** @var string Dry run: analyze small packages in memory, store and queue nothing. */
    public const MODE_DRYRUN = 'dryrun';
    /** @var string Analyze an unanalyzed package of any size now (background tasks, CLI). */
    public const MODE_NOW = 'now';
    /** @var string Analyze again and recollect the evidence even when cached (background tasks, CLI). */
    public const MODE_REFRESH = 'refresh';

    /**
     * Inspect a SCORM activity.
     *
     * @param \cm_info $cm
     * @param string $mode One of the MODE_* constants.
     * @return array Keys: cmid, scorm (record), package (analysis), evidence, recommendation, stageissues,
     *               scocount (from scorm_scoes), gradepass.
     */
    public static function inspect(\cm_info $cm, string $mode = self::MODE_READ): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $refresh = $mode === self::MODE_REFRESH;

        $packagetime = null;
        if (!in_array($scorm->scormtype, self::PACKAGE_TYPES, true)) {
            $package = ['status' => package_analyzer::STATUS_UNSUPPORTED, 'error' => 'external'];
        } else {
            $file = package_analyzer::get_package_file(\context_module::instance($cm->id));
            if (!$file) {
                $package = ['status' => package_analyzer::STATUS_MISSINGFILE, 'error' => 'nopackage'];
            } else {
                $package = match ($mode) {
                    self::MODE_NOW, self::MODE_REFRESH => package_analyzer::analyze_stored_file($file, $refresh),
                    self::MODE_AUDIT => package_analyzer::analyze_or_queue($file),
                    self::MODE_DRYRUN => package_analyzer::analyze_or_queue($file, false),
                    default => package_analyzer::get_cached($file->get_contenthash())
                        ?? ['status' => package_analyzer::STATUS_PENDING, 'error' => 'queued',
                            'contenthash' => $file->get_contenthash()],
                };
            }
            $packagetime = $file ? (int) $file->get_timemodified() : null;
        }
        // Revision 1 is the first package; each replacement with different content adds one.
        $replaced = (int) $scorm->revision >= 2 && $packagetime;

        // Page views (MODE_READ) only read stored evidence; checks and background tasks collect it.
        $evidence = runtime_evidence::get(
            (int) $scorm->id,
            $replaced ? $packagetime : null,
            $refresh,
            $mode !== self::MODE_READ
        );
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scorm', 'iteminstance' => $scorm->id,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        $gradepass = $item ? (float) $item->gradepass : null;
        $scocount = $DB->count_records('scorm_scoes', ['scorm' => $scorm->id, 'scormtype' => 'sco']);

        $recommendation = null;
        $stageissues = [];
        if (($package['status'] ?? '') === package_analyzer::STATUS_OK) {
            $recommendation = recommender::recommend($package, $evidence, $scorm, $gradepass);
            $stageissues = stage_detector::get_issues($package['stage'] ?? [], $scorm);
        }
        return [
            'cmid' => (int) $cm->id,
            'scorm' => $scorm,
            'package' => $package,
            'evidence' => $evidence,
            'recommendation' => $recommendation,
            'stageissues' => $stageissues,
            'scocount' => $scocount,
            'gradepass' => $gradepass,
            'gradeitemid' => $item ? (int) $item->id : null,
            'replaced' => (bool) $replaced,
            'packagetime' => $packagetime,
        ];
    }
}
