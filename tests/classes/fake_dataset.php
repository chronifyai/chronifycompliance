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

namespace local_chronifycompliance\tests;

use local_chronifycompliance\data\dataset;

/**
 * Dataset used by the data layer tests: copies user ids into a temporary fact table.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_dataset extends dataset {
    /** @var string Temporary table the dataset stores into. */
    public const TABLE = 'local_chronifycompliance_fakefact';

    /** @var int|null Throw while storing this batch (counting from 1), once. */
    public ?int $failonbatch = null;
    /** @var bool Return rows without a valid key. */
    public bool $badkeys = false;
    /** @var int Batches stored so far. */
    public int $stored = 0;
    /** @var bool Whether purge() was called. */
    public bool $purged = false;

    /**
     * Dataset id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'fake';
    }

    /**
     * Name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'Fake dataset';
    }

    /**
     * Read users after the high water mark.
     *
     * @param int $after
     * @param int $limit
     * @return \stdClass[]
     */
    public function read(int $after, int $limit): array {
        global $DB;
        $rows = $DB->get_records_select('user', 'id > :after', ['after' => $after], 'id', 'id AS key, id AS userid', 0, $limit);
        if ($this->badkeys) {
            foreach ($rows as $row) {
                $row->key = $after;
            }
        }
        return array_values($rows);
    }

    /**
     * Store user ids.
     *
     * @param \stdClass[] $rows
     */
    public function store(array $rows): void {
        global $DB;
        $this->stored++;
        foreach ($rows as $row) {
            $DB->insert_record(self::TABLE, ['userid' => $row->userid]);
        }
        if ($this->failonbatch === $this->stored) {
            $this->failonbatch = null;
            throw new \RuntimeException('Simulated failure');
        }
    }

    /**
     * Delete the stored rows.
     */
    public function purge(): void {
        global $DB;
        $this->purged = true;
        $DB->delete_records(self::TABLE);
    }

    /**
     * Create the temporary fact table.
     */
    public static function create_table(): void {
        global $DB;
        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_temp_table($table);
    }

    /**
     * Drop the temporary fact table.
     */
    public static function drop_table(): void {
        global $DB;
        $DB->get_manager()->drop_table(new \xmldb_table(self::TABLE));
    }
}
