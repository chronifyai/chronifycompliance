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

namespace local_completiondoctor\fix;

/**
 * Outcome of applying or undoing a fix.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fix_result {
    /** @var int|null Fix log id, set by the fixer. */
    public ?int $fixlogid = null;

    /**
     * Create a result.
     *
     * @param bool $success Whether the change was made.
     * @param array $after Machine values after the change (stored in the fix log).
     * @param string $message Human readable outcome.
     */
    public function __construct(
        /** @var bool Whether the change was made. */
        public bool $success,
        /** @var array Machine values after the change. */
        public array $after = [],
        /** @var string Human readable outcome. */
        public string $message = '',
    ) {
    }
}
