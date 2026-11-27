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

namespace local_completiondoctor\check;

/**
 * Runs the site checks. Site findings are stored with the site course id.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_auditor extends auditor {
    /**
     * Audit the site.
     *
     * @param bool $save Store the findings (otherwise a dry run that writes nothing).
     * @param string[]|null $checkids Only run these checks.
     * @return audit_result
     */
    public static function audit(bool $save = true, ?array $checkids = null): audit_result {
        $checks = self::filter_checks(check_registry::get_checks([base_check::SCOPE_SITE], true), $checkids);
        $lock = $save ? self::get_lock('site') : null;
        try {
            return self::run_checks(new audit_context(get_site()), $checks, $save);
        } finally {
            $lock?->release();
        }
    }
}
