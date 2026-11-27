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
 * Recommends Moodle SCORM completion settings from what a package reports.
 *
 * Core semantics (mod_scorm custom_completion, same in 4.5 to 5.3): completionstatusrequired is a
 * bitmask of 2 (passed) and 4 (completed); 6 means "either". Any attempt of the learner counts.
 *
 * What the package reports comes, by trust: from runtime evidence (high confidence), from the
 * authoring tool settings (medium), from the manifest mastery score (low).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recommender {
    /** @var int Status bit: passed. */
    public const PASSED = 2;
    /** @var int Status bit: completed. */
    public const COMPLETED = 4;
    /** @var int Both bits: passed or completed. */
    public const EITHER = 6;
    /** @var int forcenewattempt value "always". */
    private const FORCEATTEMPT_ALWAYS = 2;

    /**
     * What the package reports.
     *
     * @param array $package Package analysis.
     * @param array $evidence Runtime evidence.
     * @return array Keys: basis (evidence, profile, manifest or none), passed, completed, failed, incomplete,
     *               nostatus, sendsscore (bool or null when unknown).
     */
    public static function get_behavior(array $package, array $evidence): array {
        $behavior = ['basis' => 'none', 'passed' => false, 'completed' => false, 'failed' => false,
            'incomplete' => false, 'nostatus' => false, 'sendsscore' => null];

        // Evidence counts once someone reached a final status; "incomplete" or "browsed" only means
        // nobody finished yet, which says nothing about what the package sends at the end.
        $final = !empty($evidence['sufficient']) && (runtime_evidence::has_status($evidence, 'passed')
            || runtime_evidence::has_status($evidence, 'completed') || runtime_evidence::has_status($evidence, 'failed'));
        if (!empty($evidence['sufficient']) && !$evidence['withstatus']) {
            $behavior['basis'] = 'evidence';
            $behavior['sendsscore'] = $evidence['withscore'] > 0;
            $behavior['nostatus'] = true;
            return $behavior;
        }
        if ($final) {
            $behavior['basis'] = 'evidence';
            $behavior['sendsscore'] = $evidence['withscore'] > 0;
            foreach (['passed', 'completed', 'failed', 'incomplete'] as $status) {
                $behavior[$status] = runtime_evidence::has_status($evidence, $status);
            }
            // A "failed" status proves the package reports pass and fail: "passed" comes once someone passes.
            if ($behavior['failed']) {
                $behavior['passed'] = true;
            }
            return $behavior;
        }

        $mode = $package['tool']['reportingmode'] ?? null;
        if ($mode && preg_match('/^(passed|completed)-(incomplete|failed)$/', $mode, $matches)) {
            $behavior['basis'] = 'profile';
            $behavior[$matches[1]] = true;
            $behavior[$matches[2]] = true;
            // SCORM 2004 drivers set completion_status=completed together with success_status=passed.
            if ($matches[1] === 'passed' && ($package['manifest']['version'] ?? '') === 'SCORM_1.3') {
                $behavior['completed'] = true;
            }
            return $behavior;
        }

        if (($package['manifest']['masteryscore'] ?? null) !== null) {
            // Moodle sets passed/failed from the score when mastery override is on.
            $behavior['basis'] = 'manifest';
            $behavior['passed'] = true;
            $behavior['failed'] = true;
            $behavior['sendsscore'] = true;
        }
        return $behavior;
    }

    /**
     * Pass mark of the package's quiz, in percent: the authoring tool's quiz setting first, then the
     * manifest mastery score. The single source used by the recommendation and the checks.
     *
     * @param array $package Package analysis.
     * @return float|null Null when the package has no quiz pass mark we know of.
     */
    public static function get_passmark(array $package): ?float {
        $passmark = $package['tool']['passmark'] ?? ($package['manifest']['masteryscore'] ?? null);
        return $passmark === null || (float) $passmark <= 0 ? null : (float) $passmark;
    }

    /**
     * A pass mark in percent, in the raw score units Moodle compares (out of the package's maximum score).
     *
     * @param float $percent
     * @param float|null $scoremax Maximum score the package reports, null for 100.
     * @return float
     */
    public static function to_raw(float $percent, ?float $scoremax): float {
        return round($percent * ($scoremax ?? 100) / 100, 2);
    }

    /**
     * Recommend settings.
     *
     * @param array $package Package analysis.
     * @param array $evidence Runtime evidence.
     * @param \stdClass $scorm scorm record.
     * @param float|null $gradepass Pass grade of the grade item.
     * @return array Keys: behavior, settings (recommended values), current, diff (field => [current, recommended]),
     *               confidence (high, medium or low), reasons and warnings (language string references).
     */
    public static function recommend(array $package, array $evidence, \stdClass $scorm, ?float $gradepass = null): array {
        $behavior = self::get_behavior($package, $evidence);
        $current = [
            'completionstatusrequired' => $scorm->completionstatusrequired === null ? null : (int) $scorm->completionstatusrequired,
            'completionscorerequired' => $scorm->completionscorerequired === null ? null : (int) $scorm->completionscorerequired,
            'completionstatusallscos' => (int) $scorm->completionstatusallscos,
            'masteryoverride' => (int) $scorm->masteryoverride,
        ];
        $settings = $current;
        $reasons = [];
        $warnings = [];
        $scocount = (int) ($package['manifest']['scocount'] ?? 0);
        $passmark = self::get_passmark($package);
        $scoremax = runtime_evidence::get_score_max($evidence);

        if ($behavior['nostatus']) {
            if ($behavior['sendsscore']) {
                $settings['completionstatusrequired'] = null;
                // Moodle compares the raw score: a pass mark in percent is converted to the package's scale.
                $score = $passmark !== null ? self::to_raw($passmark, $scoremax)
                    : ($gradepass ?? $current['completionscorerequired'] ?? 0);
                $settings['completionscorerequired'] = (int) round($score) ?: null;
                $reasons[] = ['str' => 'rec:scoreonly'];
            } else {
                $warnings[] = ['str' => 'rec:warning:nostatus'];
                if (($package['manifest']['version'] ?? '') === 'SCORM_1.2') {
                    $warnings[] = ['str' => 'rec:warning:forcecompleted'];
                }
            }
        } else if ($behavior['passed'] && $behavior['completed']) {
            $settings['completionstatusrequired'] = self::EITHER;
            $reasons[] = ['str' => 'rec:both'];
        } else if ($behavior['passed']) {
            $settings['completionstatusrequired'] = self::PASSED;
            $reasons[] = ['str' => 'rec:passed'];
        } else if ($behavior['completed']) {
            $settings['completionstatusrequired'] = self::COMPLETED;
            $reasons[] = ['str' => 'rec:completed'];
        } else {
            $settings['completionstatusrequired'] = self::EITHER;
            $reasons[] = ['str' => 'rec:unknown'];
        }

        if ($scocount > 1 && $settings['completionstatusrequired']) {
            $settings['completionstatusallscos'] = 1;
            $reasons[] = ['str' => 'rec:multisco', 'a' => $scocount];
        }
        if (($package['manifest']['version'] ?? '') === 'SCORM_1.2' && ($package['manifest']['masteryscore'] ?? null) !== null) {
            $settings['masteryoverride'] = 1;
            $warnings[] = ['str' => 'rec:warning:masteryscore', 'a' => $package['manifest']['masteryscore']];
        }
        if ((int) $scorm->forcenewattempt === self::FORCEATTEMPT_ALWAYS) {
            $warnings[] = ['str' => 'rec:warning:forcenewattempt'];
        }

        $diff = [];
        foreach ($settings as $field => $value) {
            if ($value !== $current[$field]) {
                $diff[$field] = [$current[$field], $value];
            }
        }
        $confidence = ['evidence' => 'high', 'profile' => 'medium', 'manifest' => 'low', 'none' => 'low'][$behavior['basis']];

        return [
            'behavior' => $behavior,
            'settings' => $settings,
            'current' => $current,
            'diff' => $diff,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the current status requirement can never be met by what the package reports.
     *
     * @param array $behavior
     * @param \stdClass $scorm
     * @return string|null Reason key (passednotsent, completednotsent, scorenotsent) or null.
     */
    public static function get_mismatch(array $behavior, \stdClass $scorm): ?string {
        if (!in_array($behavior['basis'], ['evidence', 'profile'], true) || $behavior['nostatus']) {
            return null;
        }
        $required = (int) $scorm->completionstatusrequired;
        if ($required === self::PASSED && !$behavior['passed']) {
            return 'passednotsent';
        }
        if ($required === self::COMPLETED && !$behavior['completed']) {
            return 'completednotsent';
        }
        if ($scorm->completionscorerequired !== null && $behavior['sendsscore'] === false) {
            return 'scorenotsent';
        }
        return null;
    }
}
