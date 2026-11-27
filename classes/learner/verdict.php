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

namespace local_chronifycompliance\learner;

use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\finding;
use local_chronifycompliance\local\finding_store;

/**
 * Verdict of the learner diagnostic: whether the cause is the course setup (with the finding that explains it)
 * or the learner (what they still need to do), and a plain text summary for a support reply.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verdict {
    /** @var string The learner completed the course. */
    public const COMPLETE = 'complete';
    /** @var string Course completion is off, or a blocking condition is a setup problem. */
    public const SETUP = 'setup';
    /** @var string The learner still has conditions to meet. */
    public const LEARNER = 'learner';
    /** @var string Every condition is met; Moodle has not updated the completion yet. */
    public const WAITING = 'waiting';
    /** @var string The user is not enrolled. */
    public const NOTENROLLED = 'notenrolled';

    /**
     * Decide the verdict.
     *
     * A blocking condition is a setup problem when its activity was deleted or is hidden from the learner, or
     * when an open critical or warning finding of the course points at its activity. Without a blocking
     * condition, an open critical finding of the course as a whole is also a setup problem.
     *
     * @param int $courseid
     * @param array $explanation See explainer::explain().
     * @return array Keys kind (one of the constants), finding (finding|null), todo (titles the learner still needs).
     */
    public static function decide(int $courseid, array $explanation): array {
        $result = ['kind' => self::LEARNER, 'finding' => null, 'todo' => []];
        if (!$explanation['enrolled']) {
            return ['kind' => self::NOTENROLLED] + $result;
        }
        if ($explanation['completed']) {
            return ['kind' => self::COMPLETE] + $result;
        }
        if (!$explanation['enabled']) {
            return ['kind' => self::SETUP] + $result;
        }
        if ($explanation['pendingaggregation']) {
            return ['kind' => self::WAITING] + $result;
        }

        $open = array_filter(
            finding_store::get_course_findings($courseid, [finding::STATUS_OPEN]),
            fn(finding $f) => $f->severity >= base_check::SEVERITY_WARNING
        );
        usort($open, fn(finding $a, finding $b) => $b->severity <=> $a->severity);
        $bycm = [];
        $coursewide = null;
        foreach ($open as $finding) {
            if ($finding->cmid) {
                $bycm[$finding->cmid] = $bycm[$finding->cmid] ?? $finding;
            } else if (!$coursewide && $finding->severity === base_check::SEVERITY_CRITICAL) {
                $coursewide = $finding;
            }
        }

        $setup = false;
        foreach ($explanation['criteria'] as $criterion) {
            if (!$criterion['blocking']) {
                continue;
            }
            $cmid = $criterion['cmid'] ?? null;
            $missing = $cmid && !$criterion['activity'];
            $hidden = $criterion['activity'] && !$criterion['activity']['visible'];
            if ($cmid && isset($bycm[$cmid])) {
                $result['finding'] = $result['finding'] ?? $bycm[$cmid];
                $setup = true;
            } else if ($missing || $hidden) {
                $setup = true;
            } else {
                $result['todo'][] = $criterion['title'];
            }
        }
        if (!$setup && !$explanation['blocking'] && $coursewide) {
            $setup = true;
            $result['finding'] = $coursewide;
        }
        if ($setup) {
            $result['kind'] = self::SETUP;
        }
        return $result;
    }

    /**
     * Plain text summary to paste into a support reply.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param array $explanation
     * @param array $verdict See decide().
     * @return string
     */
    public static function summary(\stdClass $course, \stdClass $user, array $explanation, array $verdict): string {
        $component = 'local_chronifycompliance';
        $lines = [
            get_string('learner:summary:learner', $component, fullname($user)),
            get_string('learner:summary:course', $component, format_string($course->fullname)),
            get_string('learner:summary:status', $component, self::status_text($explanation)),
        ];
        if ($explanation['criteria']) {
            $lines[] = '';
            foreach ($explanation['criteria'] as $criterion) {
                $state = $criterion['met'] ? 'met' : ($criterion['blocking'] ? 'blocks' : 'notmet');
                $lines[] = '- ' . get_string('learner:' . $state, $component) . ': ' . $criterion['title'];
            }
        }
        $lines[] = '';
        $lines[] = self::verdict_text($verdict);
        return implode("\n", $lines);
    }

    /**
     * Status in one line: complete, or how many conditions block completion.
     *
     * @param array $explanation
     * @return string
     */
    public static function status_text(array $explanation): string {
        $component = 'local_chronifycompliance';
        if ($explanation['completed']) {
            return get_string('learner:status:complete', $component);
        }
        $blocking = (int) $explanation['blocking'];
        if (!$blocking) {
            return get_string('learner:status:notcomplete', $component);
        }
        return get_string($blocking === 1 ? 'learner:status:blockingone' : 'learner:status:blocking', $component, $blocking);
    }

    /**
     * The verdict as one sentence.
     *
     * @param array $verdict
     * @return string
     */
    public static function verdict_text(array $verdict): string {
        $component = 'local_chronifycompliance';
        if ($verdict['kind'] === self::LEARNER && $verdict['todo']) {
            return get_string('learner:verdict:learner', $component, implode('; ', $verdict['todo']));
        }
        if ($verdict['kind'] === self::SETUP && $verdict['finding']) {
            return get_string('learner:verdict:setupfinding', $component, $verdict['finding']->get_message());
        }
        return get_string('learner:verdict:' . $verdict['kind'], $component);
    }
}
