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
use local_chronifycompliance\local\audit_store;
use local_chronifycompliance\local\finding_store;
use moodle_url;
use renderer_base;

/**
 * Security view of the Security and Compliance workspace: summary tiles, findings grouped by area with one action
 * each, the passed checks (so an admin can show an auditor what was verified), and ignored findings.
 *
 * Reads only the stored site findings; Check again runs the site checks.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security_view implements \core\output\named_templatable, \renderable {
    /** @var string[] Groups in display order. */
    public const GROUPS = ['access', 'config', 'updates', 'server'];
    /** @var string[] Check id => group; checks not listed are Configuration. */
    public const CHECK_GROUPS = [
        'adminmfa' => 'access', 'inactiveadmins' => 'access', 'passwordstrength' => 'access',
        'selfregistration' => 'access', 'guestaccess' => 'access', 'riskyroles' => 'access',
        'corevulnerabilities' => 'updates', 'availableupdates' => 'updates',
        'exposedfiles' => 'server',
    ];
    /** @var string[] Filters of the summary tiles. */
    public const FILTERS = ['', 'critical', 'warning', 'passed'];

    /** @var string Active filter. */
    private string $filter;
    /** @var string Group to open, for links to one area. */
    private string $opengroup;

    /**
     * Constructor.
     *
     * @param string $filter One of FILTERS.
     * @param string $opengroup One of GROUPS to open as well, or ''.
     */
    public function __construct(string $filter = '', string $opengroup = '') {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : '';
        $this->opengroup = $opengroup;
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'local_chronifycompliance/security_view';
    }

    /**
     * Group of a check.
     *
     * @param string $checkid
     * @return string
     */
    public static function group_of(string $checkid): string {
        return self::CHECK_GROUPS[$checkid] ?? 'config';
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'local_chronifycompliance';
        $context = \context_system::instance();
        $caps = [
            'canfix' => has_capability('local/chronifycompliance:applyfix', $context),
            'canignore' => has_capability('local/chronifycompliance:ignorefinding', $context),
        ];
        $url = new moodle_url('/local/chronifycompliance/security.php');
        $checks = check_registry::get_checks([base_check::SCOPE_SITE], true, [base_check::PACK_SECURITY]);
        $audit = audit_store::get((int) SITEID);
        $audited = $audit && $audit->timeaudited;

        $open = [];
        $ignored = [];
        foreach (finding_store::get_course_findings((int) SITEID, null) as $finding) {
            if (!isset($checks[$finding->checkid])) {
                continue;
            }
            if ($finding->status === finding::STATUS_OPEN) {
                $open[] = $finding;
            } else if ($finding->status === finding::STATUS_IGNORED) {
                $ignored[] = $finding;
            }
        }
        usort($open, fn(finding $a, finding $b) => [$b->severity, $a->checkid] <=> [$a->severity, $b->checkid]);
        $counts = ['critical' => 0, 'warning' => 0];
        $failing = [];
        foreach ($open as $finding) {
            $failing[$finding->checkid] = true;
            if ($finding->severity === base_check::SEVERITY_CRITICAL) {
                $counts['critical']++;
            } else if ($finding->severity === base_check::SEVERITY_WARNING) {
                $counts['warning']++;
            }
        }
        $passed = [];
        if ($audited) {
            foreach ($checks as $id => $check) {
                if (!isset($failing[$id])) {
                    $passed[] = ['checkid' => $id, 'name' => $check->get_name(),
                        'text' => get_string('dashboard:siteok:' . $id, $component), 'description' => $check->get_description()];
                }
            }
            usort($passed, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        $groups = [];
        if ($this->filter !== 'passed') {
            foreach (self::GROUPS as $group) {
                $rows = [];
                $groupcounts = ['critical' => 0, 'warning' => 0, 'info' => 0];
                foreach ($open as $finding) {
                    if (self::group_of($finding->checkid) !== $group) {
                        continue;
                    }
                    $severity = finding_exporter::SEVERITIES[$finding->severity] ?? 'info';
                    if ($this->filter !== '' && $this->filter !== $severity) {
                        continue;
                    }
                    $groupcounts[$severity]++;
                    $rows[] = finding_exporter::export($finding, null, $caps);
                }
                if (!$rows) {
                    continue;
                }
                $parts = [];
                foreach ($groupcounts as $severity => $count) {
                    if ($count) {
                        $parts[] = get_string('security:count:' . $severity, $component, $count);
                    }
                }
                $groups[] = [
                    'group' => $group,
                    'name' => get_string('security:group:' . $group, $component),
                    'counts' => implode(', ', $parts),
                    'open' => $groupcounts['critical'] > 0 || $this->filter !== '' || $this->opengroup === $group,
                    'findings' => $rows,
                ];
            }
        }

        $tiles = [];
        $values = ['critical' => $counts['critical'], 'warning' => $counts['warning'], 'passed' => count($passed)];
        foreach ($values as $key => $value) {
            $tiles[] = [
                'group' => $key === 'passed' ? 'healthy' : $key,
                'label' => get_string('security:tile:' . $key, $component, $value),
                'value' => $value,
                'url' => (new moodle_url($url, $this->filter === $key ? [] : ['filter' => $key]))->out(false),
                'active' => $this->filter === $key,
            ];
        }
        $invite = invitation::for_page('security', new moodle_url('https://chronifyai.com'));

        return [
            'nav' => $output->render(new workspace_nav('security')),
            'checked' => $audited ? get_string('report:checkedat', $component, data_age::format_time((int) $audit->timeaudited))
                : get_string('security:neverchecked', $component),
            'canscan' => has_capability('local/chronifycompliance:runscan', $context),
            'actionurl' => $url->out(false),
            'sesskey' => sesskey(),
            'tiles' => $tiles,
            'groups' => $groups,
            'hasgroups' => (bool) $groups,
            'showpassed' => $this->filter === '' || $this->filter === 'passed',
            'passed' => $passed,
            'passedcount' => count($passed),
            'haspassed' => (bool) $passed,
            'passedopen' => $this->filter === 'passed',
            'ignored' => $this->filter === '' ? array_map(fn($f) => finding_exporter::export($f, null, $caps), $ignored) : [],
            'hasignored' => $this->filter === '' && $ignored,
            'clearurl' => $this->filter !== '' ? $url->out(false) : null,
            'invitation' => $invite ? $output->render($invite) : null,
        ];
    }
}
