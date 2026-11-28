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

namespace local_chronifycompliance\check\site;

use local_chronifycompliance\check\core_import_check;

/**
 * Moodle's own performance and status checks (Site administration > Reports > Performance overview and
 * System status), as findings of the Site and performance pack.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coreperformance extends core_import_check {
    /**
     * Check id.
     *
     * @return string
     */
    public function get_id(): string {
        return 'coreperformance';
    }

    /**
     * Performance and status checks.
     *
     * @return string[]
     */
    protected function get_types(): array {
        return ['performance', 'status'];
    }
}
