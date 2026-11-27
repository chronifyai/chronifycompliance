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
 * Finds several complete course builds inside one package (typically one per language behind a
 * wrapper page) and compares their structure.
 *
 * When more builds than SCOs exist, the builds share one tracking record: one set of resume data
 * (cmi.suspend_data, lesson location) for all languages. Storyline stores resume data by position,
 * so it only works while every build has exactly the same scenes, slides, variables and objects.
 * Each Storyline build is compared on: player version, project id, scene, slide and variable counts,
 * slide ids, and the number of objects on each slide.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_detector {
    /** @var int Maximum slide files read per build. */
    private const MAX_SLIDES = 1000;
    /** @var int Bytes read per slide file. */
    private const SLIDE_BYTES = 4 * 1024 * 1024;

    /**
     * Detect builds.
     *
     * @param package_reader $reader
     * @param int $scocount SCOs declared in the root manifest.
     * @return array Keys: builds (list), shared (more builds than SCOs), differences (list of
     *               language string references), slidesdiffer (int).
     */
    public static function detect(package_reader $reader, int $scocount): array {
        $files = $reader->get_files();
        $builds = [];
        $signatures = [];
        // Slide signatures are only compared between builds, so they are read only when there are two or more.
        $storyline = preg_grep('~^(.*?)html5/data/js/data\.js$~', array_map('strval', array_keys($files)));
        $withslides = count($storyline) >= 2;
        foreach (array_keys($files) as $path) {
            $path = (string) $path;
            if (preg_match('~^(.*?)html5/data/js/data\.js$~', $path, $matches)) {
                [$build, $signature] = self::storyline_build($reader, $files, $matches[1], $withslides);
                $builds[$matches[1]] = $build;
                $signatures[$matches[1]] = $signature;
            } else if (preg_match('~^(.*?)scormcontent/index\.html$~', $path, $matches)) {
                $builds[$matches[1]] = ['path' => $matches[1], 'tool' => 'rise', 'language' => self::guess_language($matches[1])];
            }
        }
        // Other tools: nested manifests mark separate builds.
        foreach (array_keys($files) as $path) {
            $path = (string) $path;
            if (preg_match('~^(.+/)imsmanifest\.xml$~', $path, $matches) && !isset($builds[$matches[1]])) {
                $builds[$matches[1]] = ['path' => $matches[1], 'tool' => 'unknown',
                    'language' => self::guess_language($matches[1])];
            }
        }
        ksort($builds);
        $result = ['builds' => array_values($builds), 'shared' => count($builds) >= 2 && $scocount < count($builds),
            'differences' => [], 'slidesdiffer' => 0];
        if (!$result['shared'] || count($signatures) < 2) {
            return $result;
        }

        $paths = array_keys($signatures);
        $first = array_shift($paths);
        foreach ($paths as $path) {
            $a = $builds[$first];
            $b = $builds[$path];
            $names = ['a' => $a['language'] ?? $first, 'b' => $b['language'] ?? $path];
            foreach (['version', 'slides', 'scenes', 'variables'] as $field) {
                if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                    $result['differences'][] = ['str' => 'build:diff:' . $field,
                        'a' => $names + ['va' => (string) $a[$field], 'vb' => (string) $b[$field]]];
                }
            }
            $sa = $signatures[$first];
            $sb = $signatures[$path];
            $missing = count(array_diff_key($sa, $sb)) + count(array_diff_key($sb, $sa));
            if ($missing) {
                $result['differences'][] = ['str' => 'build:diff:slideids', 'a' => $names + ['count' => $missing]];
            }
            $different = 0;
            foreach (array_intersect_key($sa, $sb) as $slide => $signature) {
                if ($signature !== $sb[$slide]) {
                    $different++;
                }
            }
            if ($different) {
                $result['differences'][] = ['str' => 'build:diff:structure',
                    'a' => $names + ['count' => $different, 'total' => count($sa)]];
            }
            $result['slidesdiffer'] = max($result['slidesdiffer'], $different + $missing);
        }
        return $result;
    }

    /**
     * Summary and per-slide signature of a Storyline build.
     *
     * @param package_reader $reader
     * @param int[] $files
     * @param string $dir Build directory with trailing slash, or ''.
     * @param bool $withslides Whether to read the slide files for their signatures.
     * @return array [build summary, slide signatures keyed by slide id]
     */
    private static function storyline_build(package_reader $reader, array $files, string $dir, bool $withslides): array {
        $data = (string) $reader->read($dir . 'html5/data/js/data.js');
        $build = [
            'path' => $dir,
            'tool' => 'storyline',
            'language' => self::guess_language($dir),
            'version' => preg_match('~"version":"([0-9][0-9.]*)"~', $data, $m) ? $m[1] : null,
            'projectid' => preg_match('~"projectId":"([^"]+)"~', $data, $m) ? $m[1] : null,
            'slides' => preg_match('~"slideCount":(\d+)~', $data, $m) ? (int) $m[1] : null,
            'scenes' => substr_count($data, '"kind":"scene"'),
            'variables' => substr_count($data, '"kind":"variable"'),
        ];
        $signatures = [];
        $prefix = preg_quote($dir . 'html5/data/js/', '~');
        foreach ($withslides ? array_keys($files) : [] as $path) {
            if (count($signatures) >= self::MAX_SLIDES) {
                break;
            }
            if (preg_match('~^' . $prefix . '([A-Za-z0-9]{11})\.js$~', (string) $path, $m)) {
                $slide = (string) $reader->read((string) $path, self::SLIDE_BYTES);
                $signatures[$m[1]] = substr_count($slide, '"kind":') . '/' . substr_count($slide, '"kind":"slidelayer"');
            }
        }
        ksort($signatures);
        return [$build, $signatures];
    }

    /**
     * Language code from a build folder name such as "scos/Course_en/" or "es-MX/".
     *
     * @param string $dir
     * @return string|null
     */
    public static function guess_language(string $dir): ?string {
        $name = basename(rtrim($dir, '/'));
        if (preg_match('~(?:^|[_\-.])([a-z]{2}(?:[_-][a-z]{2})?)$~i', $name, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }
}
