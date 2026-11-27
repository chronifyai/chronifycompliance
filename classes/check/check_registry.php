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

namespace local_chronifycompliance\check;

use local_chronifycompliance\hook\register_checks;

/**
 * Registry of all available checks: built-in checks plus checks added through the register_checks hook.
 *
 * Built-in checks are discovered from the check\completion, check\site and check\scorm namespaces.
 * Each check belongs to one pack (see base_check::get_packs()).
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_registry {
    /** @var string[] Namespaces (relative to the plugin) holding built-in checks. */
    private const BUILTIN_NAMESPACES = ['check\\site', 'check\\completion', 'check\\scorm'];

    /** @var base_check[]|null Checks keyed by id. */
    private static ?array $checks = null;

    /**
     * Get the checks.
     *
     * @param string[]|null $scopes Only checks with these scopes.
     * @param bool $enabledonly Only checks enabled by the admin.
     * @param string[]|null $packs Only checks in these packs.
     * @return base_check[] Keyed by id, sorted by id.
     */
    public static function get_checks(?array $scopes = null, bool $enabledonly = false, ?array $packs = null): array {
        $checks = self::load();
        return array_filter($checks, function (base_check $check) use ($scopes, $enabledonly, $packs): bool {
            if ($scopes !== null && !in_array($check->get_scope(), $scopes, true)) {
                return false;
            }
            if ($packs !== null && !in_array($check->get_pack(), $packs, true)) {
                return false;
            }
            return !$enabledonly || $check->is_enabled();
        });
    }

    /**
     * Get the checks grouped by pack, packs in display order.
     *
     * Every pack is listed, also when it has no checks yet.
     *
     * @param bool $enabledonly Only checks enabled by the admin.
     * @return array<string, base_check[]> Pack => checks keyed by id, sorted by id.
     */
    public static function get_checks_by_pack(bool $enabledonly = false): array {
        $grouped = array_fill_keys(base_check::get_packs(), []);
        foreach (self::get_checks(null, $enabledonly) as $id => $check) {
            $grouped[$check->get_pack()][$id] = $check;
        }
        return $grouped;
    }

    /**
     * Get a check by id.
     *
     * @param string $id
     * @return base_check|null
     */
    public static function get_check(string $id): ?base_check {
        return self::load()[$id] ?? null;
    }

    /**
     * Forget the loaded checks (tests, and after plugin installation).
     */
    public static function reset(): void {
        self::$checks = null;
    }

    /**
     * Load built-in and hook-registered checks.
     *
     * @return base_check[]
     */
    private static function load(): array {
        if (self::$checks !== null) {
            return self::$checks;
        }

        $candidates = [];
        foreach (self::BUILTIN_NAMESPACES as $namespace) {
            $classes = \core_component::get_component_classes_in_namespace('local_chronifycompliance', $namespace);
            foreach (array_keys($classes) as $classname) {
                $reflection = new \ReflectionClass($classname);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(base_check::class)) {
                    continue;
                }
                $candidates[] = new $classname();
            }
        }

        $hook = new register_checks();
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $candidates = array_merge($candidates, $hook->get_checks());

        $checks = [];
        foreach ($candidates as $check) {
            $id = $check->get_id();
            if (!preg_match('/^[a-z][a-z0-9]{1,62}$/', $id)) {
                debugging("Chronify.Compliance check id '{$id}' is invalid and was skipped", DEBUG_DEVELOPER);
                continue;
            }
            if (isset($checks[$id])) {
                debugging(
                    "Chronify.Compliance check id '{$id}' is registered twice; the second one was skipped",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            if (
                !in_array($check->get_scope(), [base_check::SCOPE_SITE, base_check::SCOPE_COURSE,
                    base_check::SCOPE_ACTIVITY], true)
            ) {
                debugging("Chronify.Compliance check '{$id}' has an invalid scope and was skipped", DEBUG_DEVELOPER);
                continue;
            }
            if (!in_array($check->get_pack(), base_check::get_packs(), true)) {
                debugging("Chronify.Compliance check '{$id}' has an invalid pack and was skipped", DEBUG_DEVELOPER);
                continue;
            }
            $checks[$id] = $check;
        }
        ksort($checks);
        self::$checks = $checks;
        return $checks;
    }
}
