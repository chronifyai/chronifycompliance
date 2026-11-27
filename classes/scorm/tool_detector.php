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

namespace local_completiondoctor\scorm;

/**
 * Detects the authoring tool of a package and extracts tool-specific settings, driven by the JSON
 * profiles in classes/scorm/profiles. Each profile documents the evidence behind its rules.
 *
 * Profile keys: name, label, experimental, fingerprints (glob or file+regex, with weight), version,
 * reporting, passmark, stage (extraction rules), fluid, firstscreen (globs of the files loaded first).
 * Extraction rule keys: file (path or glob), regex, optional decode=base64 with a "then" regex on the
 * decoded text, optional map for captured values, optional pick=mostfrequent|first.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_detector {
    /** @var int Minimum fingerprint score to accept a profile. */
    public const MIN_SCORE = 50;
    /** @var int Bytes read from a file for regex rules. */
    private const READ_BYTES = 4 * 1024 * 1024;

    /** @var array[]|null Loaded profiles keyed by name. */
    private static ?array $profiles = null;

    /**
     * All profiles.
     *
     * @return array[] Keyed by name.
     */
    public static function get_profiles(): array {
        if (self::$profiles === null) {
            self::$profiles = [];
            foreach (glob(__DIR__ . '/profiles/*.json') as $file) {
                $profile = json_decode(file_get_contents($file), true);
                if (is_array($profile) && !empty($profile['name'])) {
                    self::$profiles[$profile['name']] = $profile;
                }
            }
        }
        return self::$profiles;
    }

    /**
     * Detect the tool and extract its settings.
     *
     * @param package_reader $reader
     * @return array Keys: tool, label, confidence (0-100), experimental, version, reportingmode, passmark,
     *               stagewidth, stageheight, fluid, profile (the matched profile or null), scores.
     */
    public static function detect(package_reader $reader): array {
        $files = $reader->get_files();
        $cache = [];
        $scores = [];
        foreach (self::get_profiles() as $name => $profile) {
            $score = 0;
            foreach ($profile['fingerprints'] ?? [] as $rule) {
                if (isset($rule['regex'])) {
                    $text = self::read_matching($reader, $files, $rule['file'], $cache);
                    if ($text !== null && @preg_match('~' . $rule['regex'] . '~', $text)) {
                        $score += (int) $rule['weight'];
                    }
                } else if (self::first_match($files, $rule['glob']) !== null) {
                    $score += (int) $rule['weight'];
                }
            }
            $scores[$name] = $score;
        }
        arsort($scores);
        $best = array_key_first($scores);
        $result = [
            'tool' => 'unknown', 'label' => null, 'confidence' => 0, 'experimental' => true, 'version' => null,
            'reportingmode' => null, 'passmark' => null, 'stagewidth' => null, 'stageheight' => null, 'fluid' => null,
            'profile' => null, 'scores' => $scores,
        ];
        if ($best === null || $scores[$best] < self::MIN_SCORE) {
            return $result;
        }

        $profile = self::get_profiles()[$best];
        $result['tool'] = $best;
        $result['label'] = $profile['label'];
        $result['confidence'] = min(100, $scores[$best]);
        $result['experimental'] = !empty($profile['experimental']);
        $result['profile'] = $profile;
        $result['fluid'] = isset($profile['fluid']) ? (bool) $profile['fluid'] : null;

        $versions = [];
        foreach ($profile['version'] ?? [] as $rule) {
            $captures = self::extract($reader, $files, $rule, $cache);
            if ($captures) {
                $versions[] = $captures[0];
            }
        }
        $result['version'] = $versions ? implode(' ', array_unique($versions)) : null;

        foreach ($profile['reporting'] ?? [] as $rule) {
            $captures = self::extract($reader, $files, $rule, $cache);
            if ($captures) {
                $map = $rule['map'] ?? [];
                $result['reportingmode'] = implode('-', array_map(fn($v) => $map[strtolower($v)] ?? strtolower($v), $captures));
                break;
            }
        }
        foreach ($profile['passmark'] ?? [] as $rule) {
            $captures = self::extract($reader, $files, $rule, $cache);
            if ($captures && is_numeric($captures[0])) {
                $result['passmark'] = (float) $captures[0];
                break;
            }
        }
        foreach ($profile['stage'] ?? [] as $rule) {
            $captures = self::extract($reader, $files, $rule, $cache);
            if (count($captures) >= 2) {
                $result['stagewidth'] = (int) $captures[0];
                $result['stageheight'] = (int) $captures[1];
                $result['fluid'] = false;
                break;
            }
        }
        return $result;
    }

    /**
     * Apply an extraction rule.
     *
     * @param package_reader $reader
     * @param int[] $files
     * @param array $rule
     * @param array $cache File contents already read.
     * @return string[] Captured groups (empty when the rule does not match).
     */
    private static function extract(package_reader $reader, array $files, array $rule, array &$cache): array {
        $text = self::read_matching($reader, $files, $rule['file'], $cache);
        if ($text === null) {
            return [];
        }
        $pattern = '~' . $rule['regex'] . '~';
        if (($rule['pick'] ?? '') === 'mostfrequent') {
            if (!@preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
                return [];
            }
            $counts = [];
            foreach ($all as $match) {
                if ((int) ($match[1] ?? 0) >= 320) {
                    $key = implode('x', array_slice($match, 1));
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
            if (!$counts) {
                return [];
            }
            arsort($counts);
            return explode('x', (string) array_key_first($counts));
        }
        if (!@preg_match($pattern, $text, $match)) {
            return [];
        }
        $captures = array_slice($match, 1);
        if (($rule['decode'] ?? '') === 'base64' && isset($captures[0])) {
            $decoded = base64_decode($captures[0], true);
            if ($decoded === false || !@preg_match('~' . $rule['then'] . '~', $decoded, $match)) {
                return [];
            }
            $captures = array_slice($match, 1);
        }
        return $captures;
    }

    /**
     * Read the first file matching a path or glob.
     *
     * @param package_reader $reader
     * @param int[] $files
     * @param string $glob
     * @param array $cache
     * @return string|null
     */
    private static function read_matching(package_reader $reader, array $files, string $glob, array &$cache): ?string {
        $path = self::first_match($files, $glob);
        if ($path === null) {
            return null;
        }
        if (!array_key_exists($path, $cache)) {
            $cache[$path] = $reader->read($path, self::READ_BYTES);
        }
        return $cache[$path];
    }

    /**
     * First file path matching a glob (* also matches "/").
     *
     * @param int[] $files Sizes keyed by path.
     * @param string $glob
     * @return string|null
     */
    public static function first_match(array $files, string $glob): ?string {
        if (isset($files[$glob])) {
            return $glob;
        }
        if (!str_contains($glob, '*') && !str_contains($glob, '?')) {
            return null;
        }
        foreach (array_keys($files) as $path) {
            if (fnmatch($glob, (string) $path)) {
                return (string) $path;
            }
        }
        return null;
    }
}
