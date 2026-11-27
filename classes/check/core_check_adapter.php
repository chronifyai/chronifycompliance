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

use core\check\result;

/**
 * Exposes a Completion Doctor site check through the core Check API
 * (Site administration > Reports > System status, and admin/cli/checks.php).
 *
 * The check runs live each time; site checks are cheap and read-only.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_check_adapter extends \core\check\check {
    /**
     * Wrap a site check.
     *
     * @param base_check $check
     */
    public function __construct(
        /** @var base_check The wrapped check. */
        private base_check $check,
    ) {
        $this->set_component('local_completiondoctor');
    }

    /**
     * Id of the wrapped check.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->check->get_id();
    }

    /**
     * Name of the wrapped check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pluginname', 'local_completiondoctor') . ': ' . $this->check->get_name();
    }

    /**
     * Link to the plugin's check settings.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'local_completiondoctor_checks']),
            get_string('settings:checks', 'local_completiondoctor')
        );
    }

    /**
     * Run the check and convert its findings to a result.
     *
     * @return result
     */
    public function get_result(): result {
        if (!$this->check->is_enabled()) {
            return new result(result::NA, get_string('checkdisabled', 'local_completiondoctor'));
        }
        $findings = $this->check->run(new audit_context(get_site()));
        if (!$findings) {
            return new result(result::OK, get_string('checkok', 'local_completiondoctor'));
        }
        usort($findings, fn(finding $a, finding $b) => $b->severity <=> $a->severity);
        $statuses = [
            base_check::SEVERITY_CRITICAL => result::CRITICAL,
            base_check::SEVERITY_WARNING => result::WARNING,
            base_check::SEVERITY_INFO => result::INFO,
        ];
        $details = \html_writer::alist(array_map(fn(finding $f) => s($f->get_message()), $findings));
        return new result($statuses[$findings[0]->severity] ?? result::UNKNOWN, s($findings[0]->get_message()), $details);
    }
}
