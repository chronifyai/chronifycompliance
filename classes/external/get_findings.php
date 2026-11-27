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

namespace local_completiondoctor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_completiondoctor\check\finding;
use local_completiondoctor\local\finding_store;

/**
 * Findings across the site, filtered and paged.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_findings extends external_api {
    /** @var int Largest page size. */
    private const MAX_PERPAGE = 500;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Only courses in this category and below, 0 for all', VALUE_DEFAULT, 0),
            'severity' => new external_value(PARAM_INT, 'Minimum severity: 1 info, 2 warning, 3 critical', VALUE_DEFAULT, 1),
            'checkid' => new external_value(PARAM_ALPHANUMEXT, 'Only this check', VALUE_DEFAULT, ''),
            'status' => new external_value(
                PARAM_ALPHA,
                'open, ignored, resolved, fixed or empty for all',
                VALUE_DEFAULT,
                finding::STATUS_OPEN
            ),
            'page' => new external_value(PARAM_INT, 'Page, from 0', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Findings per page', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * Get findings.
     *
     * @param int $categoryid
     * @param int $severity
     * @param string $checkid
     * @param string $status
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function execute(
        int $categoryid = 0,
        int $severity = 1,
        string $checkid = '',
        string $status = finding::STATUS_OPEN,
        int $page = 0,
        int $perpage = 100
    ): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'categoryid',
            'severity',
            'checkid',
            'status',
            'page',
            'perpage'
        ));
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/completiondoctor:viewsitereport', $context);

        $statuses = [finding::STATUS_OPEN, finding::STATUS_IGNORED, finding::STATUS_RESOLVED, finding::STATUS_FIXED];
        if ($params['status'] !== '' && !in_array($params['status'], $statuses, true)) {
            throw new \invalid_parameter_exception('Invalid status');
        }
        $perpage = max(1, min(self::MAX_PERPAGE, $params['perpage']));
        $page = max(0, $params['page']);

        $where = ['f.severity >= :severity'];
        $sqlparams = ['severity' => $params['severity']];
        if ($params['status'] !== '') {
            $where[] = 'f.status = :status';
            $sqlparams['status'] = $params['status'];
        }
        if ($params['checkid'] !== '') {
            $where[] = 'f.checkid = :checkid';
            $sqlparams['checkid'] = $params['checkid'];
        }
        $join = '';
        if ($params['categoryid']) {
            $path = $DB->get_field('course_categories', 'path', ['id' => $params['categoryid']], MUST_EXIST);
            $join = 'JOIN {course} c ON c.id = f.courseid JOIN {course_categories} cc ON cc.id = c.category';
            $where[] = '(cc.id = :categoryid OR ' . $DB->sql_like('cc.path', ':path') . ')';
            $sqlparams += ['categoryid' => $params['categoryid'], 'path' => $path . '/%'];
        }
        $wheresql = implode(' AND ', $where);
        $total = $DB->count_records_sql(
            "SELECT COUNT(1) FROM {" . finding_store::TABLE . "} f {$join} WHERE {$wheresql}",
            $sqlparams
        );
        $records = $DB->get_records_sql("SELECT f.* FROM {" . finding_store::TABLE . "} f {$join} WHERE {$wheresql}
                                      ORDER BY f.severity DESC, f.courseid, f.id", $sqlparams, $page * $perpage, $perpage);
        $findings = [];
        foreach ($records as $record) {
            $findings[] = \local_completiondoctor\output\finding_exporter::export_for_api(finding::from_record($record));
        }
        return ['total' => $total, 'page' => $page, 'perpage' => $perpage, 'findings' => $findings];
    }

    /**
     * Result structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Findings matching the filters'),
            'page' => new external_value(PARAM_INT, 'Page returned'),
            'perpage' => new external_value(PARAM_INT, 'Page size used'),
            'findings' => new external_multiple_structure(audit_course::finding_structure()),
        ]);
    }
}
