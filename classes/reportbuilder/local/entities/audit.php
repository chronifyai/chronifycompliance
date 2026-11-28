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

namespace local_chronifycompliance\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_chronifycompliance\check\base_check;
use local_chronifycompliance\check\check_registry;
use local_chronifycompliance\reportbuilder\local\filters\check_filter;
use local_chronifycompliance\reportbuilder\local\filters\severity_filter;

/**
 * Report builder entity of the last audit of each course.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit extends base {
    /** @var string[] Severity names. */
    private const SEVERITIES = ['critical', 'warning', 'info'];

    /**
     * Database tables used.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_chronifycompliance_audit'];
    }

    /**
     * Entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('dashboard:entity', 'local_chronifycompliance');
    }

    /**
     * Add columns and filters.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias('local_chronifycompliance_audit');
        $columns = [];
        foreach (self::SEVERITIES as $severity) {
            $columns[] = (new column(
                $severity,
                new lang_string('severity:' . $severity, 'local_chronifycompliance'),
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_INTEGER)
                ->add_field("{$alias}.{$severity}")
                ->set_is_sortable(true);
        }

        // Counts by severity as colored pills, sorted by critical, then warning, then info.
        $columns[] = (new column(
            'problems',
            new lang_string('dashboard:problems', 'local_chronifycompliance'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.critical, {$alias}.warning, {$alias}.info")
            ->set_is_sortable(true, ["{$alias}.critical", "{$alias}.warning", "{$alias}.info"])
            ->set_callback(static function ($value, \stdClass $row): string {
                $pills = [];
                foreach (self::SEVERITIES as $severity) {
                    $count = (int) ($row->$severity ?? 0);
                    $pills[] = \html_writer::span(
                        $count,
                        'lcd-pill ' . ($count ? "lcd-pill-{$severity}" : 'lcd-pill-zero'),
                        ['title' => get_string('severity:' . $severity, 'local_chronifycompliance')]
                    );
                }
                return implode(' ', $pills);
            });

        $columns[] = (new column(
            'topcheck',
            new lang_string('dashboard:topcheck', 'local_chronifycompliance'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("(SELECT cdt.checkid
                            FROM {local_chronifycompliance_finding} cdt
                           WHERE cdt.courseid = {$alias}.courseid AND cdt.status = 'open'
                        GROUP BY cdt.checkid
                        ORDER BY MAX(cdt.severity) DESC, COUNT(1) DESC, cdt.checkid
                           LIMIT 1)", 'topcheck')
            ->set_callback(static function (?string $checkid): string {
                if (!$checkid) {
                    return \html_writer::span(get_string('dashboard:healthy', 'local_chronifycompliance'), 'text-success');
                }
                $check = check_registry::get_check($checkid);
                return $check ? s($check->get_name()) : s($checkid);
            });

        $columns[] = (new column(
            'timeaudited',
            new lang_string('dashboard:timeaudited', 'local_chronifycompliance'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.timeaudited")
            ->set_is_sortable(true)
            ->set_callback(static function (?int $time): string {
                if (!$time) {
                    return '';
                }
                return \html_writer::span(self::time_ago($time), '', ['title' => userdate($time)]);
            });

        return $columns;
    }

    /**
     * Filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('local_chronifycompliance_audit');
        $filters = [];
        $filters[] = (new filter(
            severity_filter::class,
            'severity',
            new lang_string('dashboard:hasseverity', 'local_chronifycompliance'),
            $this->get_entity_name(),
            "{$alias}.courseid"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static fn() => [
                base_check::SEVERITY_CRITICAL => get_string('severity:critical', 'local_chronifycompliance'),
                base_check::SEVERITY_WARNING => get_string('severity:warning', 'local_chronifycompliance'),
                base_check::SEVERITY_INFO => get_string('severity:info', 'local_chronifycompliance'),
            ]);
        $filters[] = (new filter(
            check_filter::class,
            'check',
            new lang_string('dashboard:hascheck', 'local_chronifycompliance'),
            $this->get_entity_name(),
            "{$alias}.courseid"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static fn() => array_map(
                fn($check) => $check->get_name(),
                check_registry::get_checks([base_check::SCOPE_COURSE, base_check::SCOPE_ACTIVITY])
            ));
        $filters[] = (new filter(
            number::class,
            'critical',
            new lang_string('severity:critical', 'local_chronifycompliance'),
            $this->get_entity_name(),
            "{$alias}.critical"
        ))
            ->add_joins($this->get_joins());
        return $filters;
    }

    /**
     * Short relative time: minutes, hours or days ago.
     *
     * @param int $time
     * @return string
     */
    public static function time_ago(int $time): string {
        $diff = max(0, time() - $time);
        if ($diff < HOURSECS) {
            return get_string('dashboard:ago:minutes', 'local_chronifycompliance', max(1, (int) floor($diff / MINSECS)));
        }
        if ($diff < DAYSECS) {
            return get_string('dashboard:ago:hours', 'local_chronifycompliance', (int) floor($diff / HOURSECS));
        }
        return get_string('dashboard:ago:days', 'local_chronifycompliance', (int) floor($diff / DAYSECS));
    }
}
