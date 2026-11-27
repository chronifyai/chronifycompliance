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

namespace local_chronifycompliance\reportbuilder\local\filters;

use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;

/**
 * Filter on courses that have (or do not have) an open finding with a given value of one column.
 *
 * The filter field must be the course id. Subclasses set the finding column.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class open_finding extends select {
    /**
     * Column of the finding table compared with the selected value.
     *
     * @return string
     */
    abstract protected function get_finding_column(): string;

    /**
     * SQL: EXISTS (or NOT EXISTS) an open finding of the course with the selected value.
     *
     * @param array $values
     * @return array [sql, params]
     */
    public function get_sql_filter(array $values): array {
        [$sql, $params] = parent::get_sql_filter($values);
        if ($sql === '') {
            return ['', []];
        }
        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANY_VALUE);
        $value = (string) ($values["{$this->name}_value"] ?? '');
        $courseid = $this->filter->get_field_sql();
        $valueparam = database::generate_param_name();
        $statusparam = database::generate_param_name();
        $column = $this->get_finding_column();
        $exists = "EXISTS (SELECT 1 FROM {local_chronifycompliance_finding} cdf
                            WHERE cdf.courseid = {$courseid} AND cdf.status = :{$statusparam}
                              AND cdf.{$column} = :{$valueparam})";
        $params = $this->filter->get_field_params() + [$valueparam => $value, $statusparam => 'open'];
        return [$operator === self::NOT_EQUAL_TO ? "NOT {$exists}" : $exists, $params];
    }
}
