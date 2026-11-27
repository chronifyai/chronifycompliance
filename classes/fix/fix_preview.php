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

namespace local_chronifycompliance\fix;

/**
 * Description of a change a fix would make, shown to the user before it is applied.
 *
 * The before and after arrays hold the machine values that are stored in the fix log and
 * used for undo. The rows hold the human readable diff.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fix_preview {
    /** @var array[] Human readable rows, each with keys label, before and after. */
    public array $rows = [];
    /** @var string[] Warnings to show before applying. */
    public array $warnings = [];

    /**
     * Create a preview.
     *
     * @param string $fixtype Identifier of the change, stored in the fix log.
     * @param array $before Machine values before the change.
     * @param array $after Machine values after the change.
     * @param array $params Validated user parameters the fix will use.
     */
    public function __construct(
        /** @var string Identifier of the change. */
        public string $fixtype,
        /** @var array Machine values before the change. */
        public array $before,
        /** @var array Machine values after the change. */
        public array $after,
        /** @var array Validated user parameters. */
        public array $params = [],
    ) {
    }

    /**
     * Add a human readable row to the diff.
     *
     * @param string $label What changes.
     * @param string $before Value before.
     * @param string $after Value after.
     * @return self
     */
    public function add_row(string $label, string $before, string $after): self {
        $this->rows[] = ['label' => $label, 'before' => $before, 'after' => $after];
        return $this;
    }

    /**
     * Add a warning shown before applying.
     *
     * @param string $warning
     * @return self
     */
    public function add_warning(string $warning): self {
        $this->warnings[] = $warning;
        return $this;
    }
}
