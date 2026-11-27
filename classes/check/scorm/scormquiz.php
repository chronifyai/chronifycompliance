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
use local_completiondoctor\scorm\package_analyzer;
use local_completiondoctor\scorm\recommender;
use local_completiondoctor\scorm\runtime_evidence;

/**
 * The SCORM package has a quiz, and the "Require minimum score" rule does not match it.
 *
 * Moodle compares the minimum score with the raw score the package reports, and all completion
 * rules must be met. Three cases are reported:
 * - unreachable (critical): learners' scores are out of fewer points than the minimum score;
 * - differs (warning): the minimum score is not the pass mark of the package's quiz;
 * - notrequired (info): the package completes without passing its quiz, and no minimum score is set.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scormquiz extends scorm_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'scormquiz';
    }

    /**
     * Default severity (of the "minimum score differs" case).
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
            $package = $inspection['package'];
            if (($package['status'] ?? '') !== package_analyzer::STATUS_OK) {
                continue;
            }
            $scorm = $inspection['scorm'];
            $evidence = $inspection['evidence'];
            $passmark = self::get_passmark($package);
            // Moodle compares "Require minimum score" with the raw score, so everything is compared in the
            // package's own units: the pass mark (percent) is converted with the maximum score learners report.
            $scale = runtime_evidence::get_score_max($evidence);
            $required = $scorm->completionscorerequired === null ? null : (float) $scorm->completionscorerequired;
            $params = $this->cm_params($cm);
            $details = ['scormid' => (int) $scorm->id, 'passmark' => $passmark];

            $maxes = array_keys($evidence['scoremax'] ?? []);
            $highestmax = $maxes ? max(array_map('floatval', $maxes)) : null;
            if ($required !== null && $highestmax !== null && $highestmax > 0 && $highestmax < $required) {
                $suggested = (int) ceil($passmark !== null ? recommender::to_raw($passmark, $highestmax)
                    : $highestmax * min(100, $required) / 100);
                $findings[] = $this->make_finding(
                    (int) $context->course->id,
                    'check:scormquiz:messageunreachable',
                    $params + ['required' => format_float($required, 0), 'max' => format_float($highestmax, 0),
                        'suggested' => $suggested],
                    $details + ['recommended' => ['completionscorerequired' => $suggested]],
                    (int) $cmid,
                    ['case' => 'unreachable'],
                    self::SEVERITY_CRITICAL
                );
                continue;
            }
            if ($passmark === null) {
                continue;
            }
            $passraw = recommender::to_raw($passmark, $scale);
            $passlabel = $scale !== null && abs($scale - 100) > 0.001
                ? get_string('check:scormquiz:passmarkof', 'local_completiondoctor', ['raw' => format_float($passraw, 0),
                    'max' => format_float($scale, 0), 'percent' => format_float($passmark, 0)])
                : get_string('check:scormquiz:passmarkpercent', 'local_completiondoctor', format_float($passmark, 0));
            if ($required !== null) {
                if (abs($required - $passraw) >= 0.5) {
                    $findings[] = $this->make_finding(
                        (int) $context->course->id,
                        'check:scormquiz:message' . ($required > $passraw ? 'higher' : 'lower'),
                        $params + ['required' => format_float($required, 0), 'passmark' => $passlabel,
                            'passraw' => format_float($passraw, 0)],
                        $details + ['recommended' => ['completionscorerequired' => (int) round($passraw)]],
                        (int) $cmid,
                        ['case' => 'differs']
                    );
                }
                continue;
            }
            if (self::quiz_counts($inspection)) {
                continue;
            }
            $findings[] = $this->make_finding(
                (int) $context->course->id,
                'check:scormquiz:messagenotrequired',
                $params + ['passmark' => $passlabel, 'passraw' => format_float($passraw, 0)],
                $details + ['recommended' => ['completionscorerequired' => (int) round($passraw)]],
                (int) $cmid,
                ['case' => 'notrequired'],
                self::SEVERITY_INFO
            );
        }
        return $findings;
    }


    /**
     * Pass mark of the package's quiz, in percent: the authoring tool setting, else the manifest mastery score.
     *
     * @param array $package Package analysis.
     * @return float|null Null when the package has no quiz we know of.
     */
    public static function get_passmark(array $package): ?float {
        return recommender::get_passmark($package);
    }


    /**
     * Whether completion already depends on passing the quiz: the activity requires "passed" and the
     * package only reports "passed" after the quiz is passed.
     *
     * @param array $inspection
     * @return bool
     */
    private static function quiz_counts(array $inspection): bool {
        $required = (int) $inspection['scorm']->completionstatusrequired;
        $behavior = $inspection['recommendation']['behavior'] ?? null;
        if (!$required || !$behavior) {
            return false;
        }
        // The status can never be met at all: scormstatusmismatch reports that.
        if (recommender::get_mismatch($behavior, $inspection['scorm']) !== null) {
            return true;
        }
        // Status "completed" accepted and the package reports it: learners can complete without passing.
        if (($required & recommender::COMPLETED) && $behavior['completed']) {
            return false;
        }
        return (bool) ($required & recommender::PASSED);
    }
}
