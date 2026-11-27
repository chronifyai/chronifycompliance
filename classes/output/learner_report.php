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

namespace local_completiondoctor\output;

use local_completiondoctor\check\scorm_check;
use moodle_url;
use renderer_base;

/**
 * Learner completion diagnostic.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_report implements \core\output\named_templatable, \renderable {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass|null Selected learner. */
    private ?\stdClass $user;
    /** @var array|null See explainer::explain(). */
    private ?array $explanation;
    /** @var \stdClass[] Users matching the search. */
    private array $matches;
    /** @var string Search text. */
    private string $search;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass|null $user Selected learner, or null.
     * @param array|null $explanation See explainer::explain().
     * @param array $matches Users matching the search.
     * @param string $search
     */
    public function __construct(\stdClass $course, ?\stdClass $user, ?array $explanation, array $matches, string $search) {
        $this->course = $course;
        $this->user = $user;
        $this->explanation = $explanation;
        $this->matches = $matches;
        $this->search = $search;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_completiondoctor/learner_report';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'local_completiondoctor';
        $criteria = [];
        foreach ($this->explanation['criteria'] ?? [] as $criterion) {
            $activity = $criterion['activity'];
            if ($activity) {
                $activity['statelabel'] = get_string('learner:state:' . (int) $activity['state'], $component);
                $activity['hasrules'] = (bool) $activity['rules'];
                if ($activity['scorm']) {
                    $values = [];
                    foreach ($activity['scorm']['values'] as $element => $value) {
                        $values[] = ['element' => $element, 'value' => $value];
                    }
                    $activity['scorm']['values'] = $values;
                    $activity['scorm']['hasvalues'] = (bool) $values;
                    $activity['scorm']['required'] = scorm_check::format_value(
                        'completionstatusrequired',
                        $activity['scorm']['requiredstatus']
                    );
                }
            }
            $criteria[] = ['activity' => $activity, 'timemet' => $criterion['timemet'] ? userdate($criterion['timemet']) : null]
                + $criterion;
        }
        $data = [
            'courseid' => (int) $this->course->id,
            'actionurl' => (new moodle_url('/local/completiondoctor/learner.php'))->out(false),
            'search' => $this->search,
            'matches' => array_map(fn($u) => ['id' => (int) $u->id, 'fullname' => fullname($u),
                'identity' => $u->identity ?? '',
                'url' => (new moodle_url(
                    '/local/completiondoctor/learner.php',
                    ['courseid' => $this->course->id, 'userid' => $u->id]
                ))->out(false)], $this->matches),
            'hasmatches' => (bool) $this->matches,
            'searched' => $this->search !== '',
            'user' => $this->user ? ['id' => (int) $this->user->id, 'fullname' => fullname($this->user)] : null,
            'explanation' => $this->explanation ? [
                'enabled' => $this->explanation['enabled'],
                'enrolled' => $this->explanation['enrolled'],
                'completed' => $this->explanation['completed'],
                'timecompleted' => $this->explanation['timecompleted'] ? userdate($this->explanation['timecompleted']) : null,
                'pendingaggregation' => $this->explanation['pendingaggregation'],
                'blocking' => $this->explanation['blocking'],
                'aggregation' => get_string($this->explanation['overallaggregation'] === COMPLETION_AGGREGATION_ANY
                    ? 'learner:aggregation:any' : 'learner:aggregation:all', $component),
                'criteria' => $criteria,
                'hascriteria' => (bool) $criteria,
            ] : null,
        ];
        return $data;
    }
}
