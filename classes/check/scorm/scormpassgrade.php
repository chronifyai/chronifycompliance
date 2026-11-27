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
use local_completiondoctor\fix\fix_preview;
use local_completiondoctor\fix\fix_result;

/**
 * The minimum score required for completion differs from the grade to pass of the grade item, so
 * the activity can be complete but failed (or passed but not complete).
 *
 * Only for grading methods based on the score (not "learning objects"), where the grade is the raw score.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormpassgrade extends scorm_check {
    /** @var int Grading method "learning objects" (GRADESCOES). */
    private const GRADE_SCOES = 0;

    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormpassgrade';
    }

    /**
     * Default severity.
     *
     * @return int
     */
    public function get_default_severity(): int {
        return self::SEVERITY_WARNING;
    }

    /**
     * Run the check.
     *
     * @param audit_context $context
     * @return finding[]
     */
    public function run(audit_context $context): array {
        $findings = [];
        foreach ($this->get_scorm_cms($context) as $cmid => $cm) {
            $inspection = $this->inspect($context, $cm);
            $scorm = $inspection['scorm'];
            if (
                $scorm->completionscorerequired === null || (int) $scorm->grademethod === self::GRADE_SCOES
                    || !$inspection['gradeitemid'] || (float) $inspection['gradepass'] <= 0
            ) {
                continue;
            }
            if (abs((float) $inspection['gradepass'] - (float) $scorm->completionscorerequired) < 0.01) {
                continue;
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormpassgrade:message',
                $this->cm_params($cm) + ['score' => (int) $scorm->completionscorerequired,
                    'gradepass' => format_float((float) $inspection['gradepass'], 2)],
                ['gradeitemid' => $inspection['gradeitemid'], 'score' => (int) $scorm->completionscorerequired],
                (int) $cmid
            );
        }
        return $findings;
    }

    /**
     * Fixable: align the grade to pass with the score.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return !empty($finding->details['gradeitemid']);
    }

    /**
     * Preview setting the grade to pass to the required score.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $item = \grade_item::fetch(['id' => $finding->details['gradeitemid']]);
        if (!$item) {
            throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
        }
        $score = (float) $finding->details['score'];
        $preview = new fix_preview(
            'itemgradepass',
            ['gradeitemid' => (int) $item->id, 'gradepass' => (float) $item->gradepass],
            ['gradeitemid' => (int) $item->id, 'gradepass' => $score]
        );
        return $preview->add_row(
            get_string('gradepass', 'grades'),
            format_float((float) $item->gradepass, 2),
            format_float($score, 2)
        );
    }

    /**
     * Set the grade to pass.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $this->set_gradepass($preview->after);
        return new fix_result(true, $preview->after);
    }

    /**
     * Restore the grade to pass.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        $item = \grade_item::fetch(['id' => $before['gradeitemid']]);
        $this->assert_unchanged(['gradepass' => $item ? (float) $item->gradepass : null], ['gradepass' => $after['gradepass']]);
        $this->set_gradepass($before);
        return new fix_result(true, $before);
    }

    /**
     * Write the grade to pass.
     *
     * @param array $values
     */
    private function set_gradepass(array $values): void {
        $item = \grade_item::fetch(['id' => $values['gradeitemid']]);
        if (!$item) {
            throw new \moodle_exception('error:notfixable', 'local_completiondoctor');
        }
        $item->gradepass = $values['gradepass'];
        $item->update('local_completiondoctor');
    }
}
