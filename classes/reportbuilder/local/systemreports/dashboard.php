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

namespace local_chronifycompliance\reportbuilder\local\systemreports;

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use lang_string;
use local_chronifycompliance\reportbuilder\local\entities\audit;
use moodle_url;
use pix_icon;

/**
 * Site dashboard: last audit of every course, with counts of open findings.
 *
 * Report parameters: "view" (critical, warning, info or healthy) and "checkid" restrict the rows,
 * for the tiles and "Top problems" of the dashboard.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard extends system_report {
    /**
     * Build the report.
     */
    protected function initialise(): void {
        $audit = new audit();
        $alias = $audit->get_table_alias('local_chronifycompliance_audit');
        $this->set_main_table('local_chronifycompliance_audit', $alias);
        $this->add_entity($audit);

        $siteparam = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$alias}.courseid <> :{$siteparam} AND {$alias}.timeaudited > 0",
            [$siteparam => SITEID]
        );
        $this->add_base_fields("{$alias}.courseid");
        $this->add_view_conditions($alias);

        $course = new course();
        $coursealias = $course->get_table_alias('course');
        $course->add_join("JOIN {course} {$coursealias} ON {$coursealias}.id = {$alias}.courseid");
        $this->add_entity($course);

        $category = new course_category();
        $categoryalias = $category->get_table_alias('course_categories');
        $category->add_joins($course->get_joins());
        $category->add_join("JOIN {course_categories} {$categoryalias} ON {$categoryalias}.id = {$coursealias}.category");
        $this->add_entity($category);

        $this->add_columns_from_entities([
            'course:coursefullnamewithlink',
            'course_category:name',
            'audit:problems',
            'audit:topcheck',
            'audit:timeaudited',
        ]);
        $this->get_column('course:coursefullnamewithlink')
            ->set_title(new lang_string('dashboard:course', 'local_chronifycompliance'));
        $this->get_column('course_category:name')
            ->set_title(new lang_string('dashboard:category', 'local_chronifycompliance'));

        $this->add_filters_from_entities([
            'course:fullname',
            'course_category:name',
            'audit:severity',
            'audit:check',
            'audit:critical',
        ]);
        $this->set_initial_sort_column('audit:problems', SORT_DESC);

        // Core builds the actions menu of every row even in a download, after the session is closed, and
        // rendering its icon builds the navigation, which writes to the session. Downloads have no actions column.
        if (optional_param('download', '', PARAM_ALPHA) === '') {
            $this->add_action(new action(
                new moodle_url('/local/chronifycompliance/course.php', ['id' => ':courseid']),
                new pix_icon('i/report', ''),
                [],
                false,
                new lang_string('report:title', 'local_chronifycompliance')
            ));
        }
        $this->set_downloadable(true, get_string('dashboard:filename', 'local_chronifycompliance'));
    }

    /**
     * Restrict rows to the health group or check chosen on the dashboard.
     *
     * @param string $alias Alias of the audit table.
     */
    private function add_view_conditions(string $alias): void {
        $conditions = \local_chronifycompliance\local\dashboard_data::group_conditions($alias);
        $view = $this->get_parameter('view', '', PARAM_ALPHA);
        if (isset($conditions[$view])) {
            $this->add_base_condition_sql($conditions[$view]);
        }
        $checkid = $this->get_parameter('checkid', '', PARAM_ALPHANUMEXT);
        if ($checkid !== '') {
            $checkparam = database::generate_param_name();
            $statusparam = database::generate_param_name();
            $this->add_base_condition_sql(
                "EXISTS (SELECT 1 FROM {local_chronifycompliance_finding} cdv
                WHERE cdv.courseid = {$alias}.courseid AND cdv.status = :{$statusparam} AND cdv.checkid = :{$checkparam})",
                [$checkparam => $checkid, $statusparam => 'open']
            );
        }
    }

    /**
     * Only for users with the site report capability.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('local/chronifycompliance:viewsitereport', \context_system::instance());
    }
}
