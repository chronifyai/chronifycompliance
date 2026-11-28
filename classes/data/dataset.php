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
 * A dataset of the data layer: copies and summarises source rows into the plugin's own tables.
 *
 * Pages, reports and checks never read heavy Moodle tables (logs, SCORM tracking, files). A dataset
 * reads them instead, in the background, incrementally: each refresh reads only source rows whose key
 * is greater than the high water mark of the previous one, in batches, and stores them in its own
 * tables. The refresher saves a batch and the new high water mark in one transaction, so a refresh
 * that dies part way resumes after the last saved batch, and nothing is stored twice.
 *
 * Reads happen outside a transaction, so on sites with a read-only database replica
 * ($CFG->dboptions['readonly']) Moodle sends them to the replica.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class dataset {
    /**
     * Unique id of the dataset, [a-z0-9]+.
     *
     * @return string
     */
    abstract public function get_id(): string;

    /**
     * Read up to $limit source rows whose key is greater than $after, in key order.
     *
     * Must not write. Every row must have the key as property "rowkey" (an integer that only grows,
     * usually the id of the source table).
     *
     * @param int $after High water mark: key of the last row already stored.
     * @param int $limit Maximum number of rows.
     * @return \stdClass[]
     */
    abstract public function read(int $after, int $limit): array;

    /**
     * Store a batch of rows read by read() in the dataset's own tables.
     *
     * Called inside a transaction together with the update of the high water mark.
     *
     * @param \stdClass[] $rows
     */
    abstract public function store(array $rows): void;

    /**
     * Name of the dataset for administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('dataset:' . $this->get_id(), 'local_chronifycompliance');
    }

    /**
     * High water mark to start from on the first refresh. Datasets with a limited first load
     * (for example the last 90 days of logs) return the key just before the first row to load.
     *
     * @return int
     */
    public function get_start(): int {
        return 0;
    }

    /**
     * Delete everything the dataset stored, before it is loaded again from the start.
     */
    public function purge(): void {
    }
}
