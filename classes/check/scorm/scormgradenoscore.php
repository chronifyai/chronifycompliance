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

namespace local_completiondoctor\check\scorm;

use local_completiondoctor\check\audit_context;
use local_completiondoctor\check\finding;
use local_completiondoctor\check\scorm_check;
use local_completiondoctor\fix\activity_completion;
use local_completiondoctor\fix\fix_preview;
use local_completiondoctor\fix\fix_result;
use local_completiondoctor\scorm\package_analyzer;

/**
 * The SCORM activity requires a grade, but the package has no quiz and never sends a score.
 *
 * Moodle writes the grade when the package reports "completed" or "passed": without a score that
 * grade is 0. With a grade to pass or "Require passing grade" the 0 is always a fail, so learners can
 * never complete (critical). With "Require grade" only, the 0 completes the activity when the learner
 * finishes, so the rule only works as "finished" and every learner shows 0 in the gradebook (info).
 * Activities graded by "learning objects" are skipped: their grade is the number of completed SCOs.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormgradenoscore extends scorm_check {
    /** @var int Grading method "learning objects" (GRADESCOES). */
    private const GRADE_SCOES = 0;
    /** @var string[] Authoring tools whose profile reliably shows whether there is a quiz. */
    private const RELIABLE_TOOLS = ['storyline', 'rise'];

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormgradenoscore';
    }

    /**
     * Default severity (learners can never complete).
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        global $DB;
        $findings = [];
        foreach ($this->get_scorm_cms($context) as $cmid => $cm) {
            $usesgrade = $cm->completiongradeitemnumber !== null;
            $passgrade = !empty($cm->completionpassgrade);
            if (!$usesgrade && !$passgrade) {
                continue;
            }
            $inspection = $this->inspect($context, $cm);
            if (
                (int) $inspection['scorm']->grademethod === self::GRADE_SCOES
                    || ($inspection['package']['status'] ?? '') !== package_analyzer::STATUS_OK
            ) {
                continue;
            }
            $basis = self::no_score_basis($inspection);
            if ($basis === null) {
                continue;
            }
            $gradepass = (float) $inspection['gradepass'];
            $hidden = $inspection['gradeitemid']
                && (int) $DB->get_field('grade_items', 'hidden', ['id' => $inspection['gradeitemid']]) !== 0;
            $blocked = $passgrade || ($gradepass > 0 && !$hidden);
            $status = $inspection['recommendation']['settings']['completionstatusrequired'] ?? null;
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormgradenoscore:message' . ($blocked ? 'blocked' : 'zero'),
                $this->cm_params($cm) + ['gradepass' => format_float($gradepass, 2),
                    'basis' => ['str' => 'check:scormgradenoscore:basis:' . $basis]],
                ['scormid' => (int) $inspection['scorm']->id, 'cmid' => (int) $cmid, 'status' => $status],
                (int) $cmid,
                [],
                $blocked ? self::SEVERITY_CRITICAL : self::SEVERITY_INFO
            );
        }
        return $findings;
    }

    /**
     * Why we know the package sends no score.
     *
     * @param array $inspection
     * @return string|null "evidence" (learners' attempts report a status but never a score), "package"
     *                     (the authoring tool settings have no quiz), or null when it may send a score.
     */
    private static function no_score_basis(array $inspection): ?string {
        $evidence = $inspection['evidence'];
        $package = $inspection['package'];
        $sendsscore = $inspection['recommendation']['behavior']['sendsscore'] ?? null;
        if ((int) ($evidence['withscore'] ?? 0) > 0 || $sendsscore === true) {
            return null;
        }
        if (!empty($evidence['sufficient']) && (int) ($evidence['withstatus'] ?? 0) > 0) {
            return 'evidence';
        }
        if (scormquiz::get_passmark($package) !== null) {
            return null;
        }
        $tool = $package['tool']['tool'] ?? null;
        $mode = (string) ($package['tool']['reportingmode'] ?? '');
        if (in_array($tool, self::RELIABLE_TOOLS, true) && $mode !== '' && !str_starts_with($mode, 'passed')) {
            return 'package';
        }
        return null;
    }

    /**
     * Fixable when we know which status the package reports.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['status']) && !empty($finding->details['scormid']);
    }

    /**
     * Preview: remove the grade rules and require the status the package reports.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        global $DB;
        $cm = $DB->get_record(
            'course_modules',
            ['id' => $finding->details['cmid']],
            'id, completiongradeitemnumber, completionpassgrade',
            MUST_EXIST
        );
        $scorm = $DB->get_record('scorm', ['id' => $finding->details['scormid']], 'id, completionstatusrequired', MUST_EXIST);
        $current = $scorm->completionstatusrequired === null ? null : (int) $scorm->completionstatusrequired;
        $status = (int) $finding->details['status'];
        $before = ['cmid' => (int) $cm->id, 'scormid' => (int) $scorm->id,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber === null ? null : (int) $cm->completiongradeitemnumber,
            'completionpassgrade' => (int) $cm->completionpassgrade, 'completionstatusrequired' => $current];
        $after = ['completiongradeitemnumber' => null, 'completionpassgrade' => 0,
            'completionstatusrequired' => $current ?: $status] + $before;
        $preview = new fix_preview('scormgraderules', $before, $after);
        $component = 'local_completiondoctor';
        if ($before['completiongradeitemnumber'] !== null) {
            $preview->add_row(get_string('completionusegrade', 'completion'), get_string('yes'), get_string('no'));
        }
        if ($before['completionpassgrade']) {
            $preview->add_row(get_string('completionpassgrade', 'completion'), get_string('yes'), get_string('no'));
        }
        if (!$current) {
            $preview->add_row(
                get_string('scorm:field:completionstatusrequired', $component),
                self::format_value('completionstatusrequired', null),
                self::format_value('completionstatusrequired', $status)
            );
        }
        $preview->add_warning(get_string('fix:warning:norecalculation', $component));
        return $preview;
    }

    /**
     * Apply.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $this->write($preview->after);
        return new fix_result(true, $preview->after);
    }

    /**
     * Undo.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        $current = activity_completion::current(
            (int) $after['cmid'],
            ['completiongradeitemnumber', 'completionpassgrade'],
            'scorm',
            ['completionstatusrequired']
        );
        $this->assert_unchanged($current, array_intersect_key($after, $current));
        $this->write($before);
        return new fix_result(true, $before);
    }

    /**
     * Write the completion rules of the activity.
     *
     * @param array $values
     */
    private function write(array $values): void {
        activity_completion::update(
            (int) $values['cmid'],
            [
                'completiongradeitemnumber' => $values['completiongradeitemnumber'],
                'completionpassgrade' => $values['completionpassgrade'],
            ],
            ['completionstatusrequired' => $values['completionstatusrequired']]
        );
    }
}
