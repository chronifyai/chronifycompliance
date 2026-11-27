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

namespace local_chronifycompliance\local;

/**
 * First run setup: the site's sector and country, the chosen frameworks, and the quick first pass.
 *
 * Everything is stored as plugin settings. Sector and country only pick defaults; the frameworks are
 * used by the framework views (Stage D).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup {
    /** @var string[] Sectors. */
    public const SECTORS = ['highered', 'k12', 'corporate', 'nonprofit', 'government', 'other'];
    /** @var string[] Frameworks available now. */
    public const FRAMEWORKS = ['nistcsf2', 'gdpr', 'ferpa'];
    /** @var string[] Frameworks shown as coming soon. */
    public const SOON = ['iso27001', 'soc2'];
    /** @var int Courses checked first, so the dashboard fills within minutes. */
    public const SAMPLE = 20;
    /** @var string[] Countries where GDPR (or the UK and Swiss equivalents) applies: EU, EEA, UK, Switzerland. */
    private const GDPR_COUNTRIES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH'];
    /** @var string Plugin. */
    private const COMPONENT = 'local_chronifycompliance';

    /**
     * Whether the setup was finished (the first check started from it).
     *
     * @return bool
     */
    public static function is_done(): bool {
        return (bool) get_config(self::COMPONENT, 'setupdone');
    }

    /**
     * Whether the wizard should open instead of the dashboard: not finished, not skipped, and no course checked yet.
     *
     * @return bool
     */
    public static function needs_wizard(): bool {
        global $DB;
        if (self::is_done() || get_config(self::COMPONENT, 'setupskipped')) {
            return false;
        }
        return !$DB->record_exists('local_chronifycompliance_audit', []);
    }

    /**
     * Skip the wizard; the dashboard then shows a Finish setup card.
     */
    public static function skip(): void {
        set_config('setupskipped', time(), self::COMPONENT);
    }

    /**
     * Stored sector, or '' before step 1.
     *
     * @return string
     */
    public static function get_sector(): string {
        $sector = (string) get_config(self::COMPONENT, 'sector');
        return in_array($sector, self::SECTORS, true) ? $sector : '';
    }

    /**
     * Stored country, or the site's default country.
     *
     * @return string Two letter code, or ''.
     */
    public static function get_country(): string {
        global $CFG;
        return (string) (get_config(self::COMPONENT, 'country') ?: ($CFG->country ?? ''));
    }

    /**
     * Save step 1.
     *
     * @param string $sector
     * @param string $country
     */
    public static function save_site(string $sector, string $country): void {
        if (!in_array($sector, self::SECTORS, true)) {
            throw new \invalid_parameter_exception('Unknown sector ' . $sector);
        }
        $previous = [self::get_sector(), self::get_country()];
        set_config('sector', $sector, self::COMPONENT);
        set_config('country', $country, self::COMPONENT);
        // New answers in step 1 mean new suggestions in step 2.
        if ($previous !== [$sector, $country]) {
            unset_config('frameworks', self::COMPONENT);
        }
    }

    /**
     * Frameworks suggested for a sector and country: NIST CSF 2.0 always, GDPR in Europe, FERPA for US education.
     *
     * @param string $sector
     * @param string $country
     * @return string[]
     */
    public static function suggest_frameworks(string $sector, string $country): array {
        $frameworks = ['nistcsf2'];
        if (in_array($country, self::GDPR_COUNTRIES, true)) {
            $frameworks[] = 'gdpr';
        }
        if ($country === 'US' && in_array($sector, ['highered', 'k12'], true)) {
            $frameworks[] = 'ferpa';
        }
        return $frameworks;
    }

    /**
     * Chosen frameworks, or the suggestions before step 2 was saved.
     *
     * @return string[]
     */
    public static function get_frameworks(): array {
        $stored = get_config(self::COMPONENT, 'frameworks');
        if ($stored === false) {
            return self::suggest_frameworks(self::get_sector(), self::get_country());
        }
        return array_values(array_intersect(self::FRAMEWORKS, array_filter(explode(',', (string) $stored))));
    }

    /**
     * Save step 2.
     *
     * @param string[] $frameworks
     */
    public static function save_frameworks(array $frameworks): void {
        set_config('frameworks', implode(',', array_intersect(self::FRAMEWORKS, $frameworks)), self::COMPONENT);
    }

    /**
     * Up to SAMPLE recently changed visible courses, checked first.
     *
     * @return int[]
     */
    public static function sample_courses(): array {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT id FROM {course} WHERE id <> :siteid AND visible = 1 ORDER BY timemodified DESC, id DESC',
            ['siteid' => SITEID],
            0,
            self::SAMPLE
        );
        return array_map('intval', array_keys($records));
    }

    /**
     * Step 3: check the site now and start a scan that checks the sample courses first.
     *
     * @param int $userid
     * @return int|null Scan id, or null when a scan was already running.
     */
    public static function start(int $userid): ?int {
        set_config('setupdone', time(), self::COMPONENT);
        if (scan_manager::get_active_scan()) {
            return null;
        }
        \local_chronifycompliance\check\site_auditor::audit(true);
        return scan_manager::start_scan($userid, null, null, null, self::sample_courses());
    }
}
