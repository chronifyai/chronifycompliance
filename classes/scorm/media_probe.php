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
 * Reads the bitrate of media files in pure PHP, from a forward-only stream.
 *
 * MP4/M4A/M4V/MOV: walks the top-level atoms to moov/mvhd to get the duration (skipping mdat by
 * reading and discarding, as zip streams cannot seek). MP3: bitrate from the first frame header.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media_probe {
    /** @var int Most bytes read from one media file; past it the bitrate is reported as unknown. */
    public const READ_BUDGET = 2 * 1024 * 1024;
    /** @var int Bytes still allowed for the file being probed. */
    private static int $budget = self::READ_BUDGET;

    /** @var int[] MPEG-1 layer III bitrates in kbps by index. */
    private const MP3_BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];

    /**
     * Bitrate of a media file.
     *
     * @param resource $stream Forward-only stream at the start of the file.
     * @param string $extension Lower case file extension.
     * @param int $size File size in bytes.
     * @return float|null Kbps, null when unknown.
     */
    public static function get_kbps($stream, string $extension, int $size): ?float {
        self::$budget = self::READ_BUDGET;
        if (in_array($extension, ['mp4', 'm4a', 'm4v', 'mov'], true)) {
            $duration = self::mp4_duration($stream);
            return $duration ? round($size * 8 / $duration / 1000, 2) : null;
        }
        if ($extension === 'mp3') {
            return self::mp3_kbps($stream);
        }
        return null;
    }

    /**
     * Duration of an MP4 file from moov/mvhd.
     *
     * @param resource $stream
     * @return float|null Seconds.
     */
    public static function mp4_duration($stream): ?float {
        // Top-level atoms.
        while (!feof($stream)) {
            $header = self::read_exact($stream, 8);
            if ($header === null) {
                return null;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headersize = 8;
            if ($size === 1) {
                $large = self::read_exact($stream, 8);
                if ($large === null) {
                    return null;
                }
                $parts = unpack('N2', $large);
                $size = $parts[1] * 4294967296 + $parts[2];
                $headersize = 16;
            } else if ($size === 0) {
                return null;
            }
            if ($size < $headersize) {
                return null;
            }
            $body = $size - $headersize;
            if ($type === 'moov') {
                return self::find_mvhd($stream, $body);
            }
            if (!self::skip($stream, $body)) {
                return null;
            }
        }
        return null;
    }

    /**
     * Find mvhd inside moov and return its duration.
     *
     * @param resource $stream
     * @param int $remaining Bytes of the moov body.
     * @return float|null
     */
    private static function find_mvhd($stream, int $remaining): ?float {
        while ($remaining >= 8) {
            $header = self::read_exact($stream, 8);
            if ($header === null) {
                return null;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            if ($size < 8 || $size > $remaining) {
                return null;
            }
            if ($type === 'mvhd') {
                $body = self::read_exact($stream, min($size - 8, 120));
                if ($body === null) {
                    return null;
                }
                $version = ord($body[0]);
                if ($version === 1 && strlen($body) >= 32) {
                    $timescale = unpack('N', substr($body, 20, 4))[1];
                    $parts = unpack('N2', substr($body, 24, 8));
                    $duration = $parts[1] * 4294967296 + $parts[2];
                } else if (strlen($body) >= 20) {
                    $timescale = unpack('N', substr($body, 12, 4))[1];
                    $duration = unpack('N', substr($body, 16, 4))[1];
                } else {
                    return null;
                }
                return $timescale > 0 && $duration > 0 ? $duration / $timescale : null;
            }
            if (!self::skip($stream, $size - 8)) {
                return null;
            }
            $remaining -= $size;
        }
        return null;
    }

    /**
     * Bitrate of the first MP3 frame (an ID3v2 tag is skipped).
     *
     * @param resource $stream
     * @return float|null
     */
    public static function mp3_kbps($stream): ?float {
        $head = self::read_exact($stream, 10);
        if ($head === null) {
            return null;
        }
        $buffer = $head;
        if (str_starts_with($head, 'ID3')) {
            $bytes = array_values(unpack('C4', substr($head, 6, 4)));
            $tagsize = ($bytes[0] << 21) | ($bytes[1] << 14) | ($bytes[2] << 7) | $bytes[3];
            if (!self::skip($stream, $tagsize)) {
                return null;
            }
            $buffer = '';
        }
        $buffer .= (string) fread($stream, 4096);
        $length = strlen($buffer);
        for ($i = 0; $i < $length - 3; $i++) {
            if (ord($buffer[$i]) !== 0xFF || (ord($buffer[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }
            $b1 = ord($buffer[$i + 1]);
            $b2 = ord($buffer[$i + 2]);
            // MPEG version 1 (bits 11), layer III (bits 01).
            if ((($b1 >> 3) & 0x03) !== 0x03 || (($b1 >> 1) & 0x03) !== 0x01) {
                continue;
            }
            $index = $b2 >> 4;
            if ($index > 0 && $index < 15) {
                return (float) self::MP3_BITRATES[$index];
            }
        }
        return null;
    }

    /**
     * Read exactly n bytes.
     *
     * @param resource $stream
     * @param int $length
     * @return string|null
     */
    private static function read_exact($stream, int $length): ?string {
        if ($length > self::$budget) {
            return null;
        }
        self::$budget -= $length;
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return strlen($data) === $length ? $data : null;
    }

    /**
     * Skip bytes of a forward-only stream.
     *
     * @param resource $stream
     * @param int $length
     * @return bool False when the stream ended first.
     */
    private static function skip($stream, int $length): bool {
        if ($length > self::$budget) {
            return false;
        }
        self::$budget -= $length;
        while ($length > 0) {
            $chunk = fread($stream, min($length, 1048576));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $length -= strlen($chunk);
        }
        return true;
    }
}
