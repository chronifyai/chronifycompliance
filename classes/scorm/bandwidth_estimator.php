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
 * Static estimate of the connection speed a package needs.
 *
 * - Start-up payload: the launch file plus the profile's "first screen" files (for unknown tools:
 *   the launch file and every script and style sheet, an upper bound).
 * - Largest slide: the largest asset that is downloaded whole before it shows (images, scripts,
 *   data, styles, fonts). Video and audio stream, so they are covered by their bitrate instead, and
 *   attachments (PDFs, documents, Storyline external_files) only load when opened.
 * - Media bitrate: from the MP4 mvhd duration or the MP3 frame header, for the largest media files.
 *
 * minmbps = max(startup bits / target start-up time, largest slide bits / target slide time,
 *               highest media kbps / 1000 x headroom).
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bandwidth_estimator {
    /** @var int Media files probed per package (largest first). */
    private const MAX_PROBES = 10;
    /** @var int Packages with more files than this are flagged: each file is a separate request. */
    public const MANY_FILES = 1000;

    /** @var string[] Types downloaded whole when a slide shows (attachments such as PDFs open on demand). */
    private const SLIDE_TYPES = ['image', 'script', 'style', 'font'];

    /** @var string[][] File type groups by extension. */
    private const TYPES = [
        'video' => ['mp4', 'm4v', 'mov', 'webm', 'ogv', 'flv'],
        'audio' => ['mp3', 'm4a', 'ogg', 'wav', 'aac'],
        'image' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp'],
        'script' => ['js', 'json'],
        'style' => ['css'],
        'font' => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
        'html' => ['html', 'htm', 'xml'],
    ];

    /**
     * Estimate.
     *
     * @param package_reader $reader
     * @param string|null $launch Launch file, relative to the package root.
     * @param array|null $profile Tool profile.
     * @return array Keys: bytesbytype, filesbytype, totalbytes, filecount, startupbytes, maxslidebytes,
     *               maxslidefile, maxvideokbps, mediaprobed, minmbps, manyfiles, startupseconds3mbps.
     */
    public static function estimate(package_reader $reader, ?string $launch, ?array $profile): array {
        $files = $reader->get_files();
        $startup = self::startup_files($files, $launch, $profile);
        $startupset = array_flip($startup);
        $startupbytes = array_sum(array_intersect_key($files, $startupset));
        $ignore = $profile['ignore'] ?? [];

        $bytesbytype = [];
        $filesbytype = [];
        $media = [];
        $maxslide = 0;
        $maxslidefile = null;
        foreach ($files as $path => $size) {
            $type = self::get_type((string) $path);
            $bytesbytype[$type] = ($bytesbytype[$type] ?? 0) + $size;
            $filesbytype[$type] = ($filesbytype[$type] ?? 0) + 1;
            if ($type === 'video' || $type === 'audio') {
                $media[$path] = $size;
            } else if (
                in_array($type, self::SLIDE_TYPES, true) && !isset($startupset[$path]) && $size > $maxslide
                    && !str_contains((string) $path, 'external_files/') && !self::matches_any((string) $path, $ignore)
            ) {
                $maxslide = $size;
                $maxslidefile = (string) $path;
            }
        }

        arsort($media);
        $maxkbps = null;
        $probed = 0;
        foreach (array_slice($media, 0, self::MAX_PROBES, true) as $path => $size) {
            $stream = $reader->stream((string) $path);
            if (!$stream) {
                continue;
            }
            try {
                $kbps = media_probe::get_kbps($stream, strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)), $size);
            } finally {
                fclose($stream);
            }
            $probed++;
            if ($kbps !== null) {
                $maxkbps = max($maxkbps ?? 0, $kbps);
            }
        }

        $targetstartup = (float) get_config('local_completiondoctor', 'targetstartup') ?: 5.0;
        $targetslide = (float) get_config('local_completiondoctor', 'targetslide') ?: 2.0;
        $headroom = (float) get_config('local_completiondoctor', 'videoheadroom') ?: 1.5;
        $minmbps = max(
            $startupbytes * 8 / $targetstartup / 1000000,
            $maxslide * 8 / $targetslide / 1000000,
            ($maxkbps ?? 0) / 1000 * $headroom
        );

        return [
            'bytesbytype' => $bytesbytype,
            'filesbytype' => $filesbytype,
            'totalbytes' => array_sum($files),
            'filecount' => count($files),
            'startupbytes' => $startupbytes,
            'startupfiles' => count($startup),
            'maxslidebytes' => $maxslide,
            'maxslidefile' => $maxslidefile,
            'maxvideokbps' => $maxkbps,
            'mediaprobed' => $probed,
            'minmbps' => round($minmbps, 2),
            'manyfiles' => count($files) > self::MANY_FILES,
            'startupseconds3mbps' => round($startupbytes * 8 / 3000000, 1),
        ];
    }

    /**
     * Files loaded for the first screen.
     *
     * @param int[] $files
     * @param string|null $launch
     * @param array|null $profile
     * @return string[]
     */
    private static function startup_files(array $files, ?string $launch, ?array $profile): array {
        $launchfile = $launch ? strtok($launch, '?#') : null;
        $startup = $launchfile && isset($files[$launchfile]) ? [$launchfile] : [];
        if ($profile && !empty($profile['firstscreen'])) {
            foreach (array_keys($files) as $path) {
                foreach ($profile['firstscreen'] as $glob) {
                    if (fnmatch($glob, (string) $path)) {
                        $startup[] = (string) $path;
                        break;
                    }
                }
            }
        } else {
            foreach (array_keys($files) as $path) {
                if (in_array(self::get_type((string) $path), ['script', 'style'], true)) {
                    $startup[] = (string) $path;
                }
            }
        }
        return array_values(array_unique($startup));
    }

    /**
     * Whether a path matches one of the globs.
     *
     * @param string $path
     * @param string[] $globs
     * @return bool
     */
    private static function matches_any(string $path, array $globs): bool {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Type group of a file.
     *
     * @param string $path
     * @return string
     */
    public static function get_type(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach (self::TYPES as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }
        return 'other';
    }
}
