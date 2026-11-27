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

namespace local_chronifycompliance\data;

/**
 * Registry of the datasets of the data layer, discovered from the data\datasets namespace.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataset_registry {
    /** @var dataset[]|null Datasets keyed by id. */
    private static ?array $datasets = null;

    /**
     * All datasets.
     *
     * @return dataset[] Keyed by id, sorted by id.
     */
    public static function get_datasets(): array {
        if (self::$datasets === null) {
            $datasets = [];
            $classes = \core_component::get_component_classes_in_namespace('local_chronifycompliance', 'data\\datasets');
            foreach (array_keys($classes) as $classname) {
                $reflection = new \ReflectionClass($classname);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(dataset::class)) {
                    continue;
                }
                $dataset = new $classname();
                $datasets[$dataset->get_id()] = $dataset;
            }
            ksort($datasets);
            self::$datasets = $datasets;
        }
        return self::$datasets;
    }

    /**
     * A dataset by id.
     *
     * @param string $id
     * @return dataset|null
     */
    public static function get_dataset(string $id): ?dataset {
        return self::get_datasets()[$id] ?? null;
    }

    /**
     * Use these datasets instead of the discovered ones (tests only).
     *
     * @param dataset[]|null $datasets Null goes back to discovery.
     */
    public static function phpunit_set(?array $datasets): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('Only for unit tests');
        }
        if ($datasets === null) {
            self::$datasets = null;
            return;
        }
        $keyed = [];
        foreach ($datasets as $dataset) {
            $keyed[$dataset->get_id()] = $dataset;
        }
        ksort($keyed);
        self::$datasets = $keyed;
    }
}
