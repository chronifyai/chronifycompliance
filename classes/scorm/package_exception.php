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

namespace local_chronifycompliance\scorm;

/**
 * A package cannot be analyzed (not a zip, too large, unsafe, ...).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_exception extends \moodle_exception {
    /**
     * Create the exception.
     *
     * @param string $reason Language string suffix, see package:error:*.
     * @param mixed $a Language string parameter.
     */
    public function __construct(
        /** @var string Short reason code. */
        public readonly string $reason,
        $a = null,
    ) {
        parent::__construct('package:error:' . $reason, 'local_chronifycompliance', '', $a);
    }
}
