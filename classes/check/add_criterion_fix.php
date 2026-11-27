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

namespace local_completiondoctor\check;

use local_completiondoctor\fix\criteria_editor;
use local_completiondoctor\fix\fix_preview;
use local_completiondoctor\fix\fix_result;

/**
 * Fix that adds the activity of a finding to the course completion criteria.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait add_criterion_fix {
    /**
     * Fixable when the finding is about an activity.
     *
     * @param finding $finding
     * @return bool
     */
    public function can_fix(finding $finding): bool {
        return $finding->cmid !== null;
    }

    /**
     * Preview adding the activity to the criteria.
     *
     * @param finding $finding
     * @param array $params
     * @return fix_preview
     */
    public function preview_fix(finding $finding, array $params = []): fix_preview {
        $component = 'local_completiondoctor';
        $preview = new fix_preview(
            'addactivitycriterion',
            ['cmid' => $finding->cmid, 'courseid' => $finding->courseid, 'required' => 0],
            ['cmid' => $finding->cmid, 'courseid' => $finding->courseid, 'required' => 1]
        );
        $preview->add_row(
            get_string('fix:requiredactivity', $component, $finding->messageparams['name'] ?? $finding->cmid),
            get_string('fix:notrequired', $component),
            get_string('fix:required', $component)
        );
        $learners = criteria_editor::count_learners_with_progress($finding->courseid);
        if ($learners) {
            $preview->add_warning(get_string('fix:warning:learnerprogress', $component, $learners));
        }
        return $preview;
    }

    /**
     * Add the criterion.
     *
     * @param finding $finding
     * @param fix_preview $preview
     * @param int $userid
     * @return fix_result
     */
    public function apply_fix(finding $finding, fix_preview $preview, int $userid): fix_result {
        $added = criteria_editor::add_activity_criterion($finding->courseid, (int) $finding->cmid);
        return new fix_result(true, ['added' => (int) $added] + $preview->after);
    }

    /**
     * Remove the added criterion.
     *
     * @param string $fixtype
     * @param array $before
     * @param array $after
     * @param \stdClass $fixlog
     * @return fix_result
     */
    public function undo_fix(string $fixtype, array $before, array $after, \stdClass $fixlog): fix_result {
        // Nothing to remove when the activity was already required.
        if (empty($after['added'])) {
            return new fix_result(true, $before);
        }
        if (!criteria_editor::remove_activity_criterion((int) $after['courseid'], (int) $after['cmid'])) {
            throw new \moodle_exception('error:undochanged', 'local_completiondoctor');
        }
        return new fix_result(true, $before);
    }
}
