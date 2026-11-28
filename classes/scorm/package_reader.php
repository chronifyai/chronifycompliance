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

use ZipArchive;

/**
 * Read-only access to a SCORM package zip without extracting it.
 *
 * Entries with unsafe names (absolute paths, "..", backslashes, NUL bytes) are never read and are
 * counted in $unsafeentries. The total uncompressed size and the number of entries are limited, and
 * text reads are capped.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_reader {
    /** @var int Default maximum total uncompressed size in MB. */
    public const DEFAULT_MAX_MB = 2048;
    /** @var int Maximum number of entries. */
    public const MAX_ENTRIES = 50000;
    /** @var int Maximum bytes read from a text file. */
    public const MAX_TEXT_BYTES = 8 * 1024 * 1024;

    /** @var array[] Files keyed by path: size, csize, index. */
    public array $entries = [];
    /** @var int Unsafe entries that were skipped. */
    public int $unsafeentries = 0;
    /** @var int Total uncompressed size. */
    public int $totalbytes = 0;
    /** @var string|null Directory of imsmanifest.xml ('' for the root), null when there is none. */
    public ?string $root = null;

    /** @var ZipArchive Open archive. */
    private ZipArchive $zip;
    /** @var string|null Temporary copy to delete on close. */
    private ?string $tempfile = null;

    /**
     * Open a zip file.
     *
     * @param string $path Path to the zip.
     * @param bool $istemp Delete the file on close.
     * @throws package_exception
     */
    public function __construct(string $path, bool $istemp = false) {
        if ($istemp) {
            $this->tempfile = $path;
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->cleanup();
            throw new package_exception('notzip');
        }
        try {
            $this->index();
        } catch (package_exception $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * Open a package stored in the Moodle file system.
     *
     * @param \stored_file $file
     * @return self
     * @throws package_exception When the file content is missing or not a zip.
     */
    public static function from_stored_file(\stored_file $file): self {
        $filesystem = get_file_storage()->get_file_system();
        if (!$filesystem->is_file_readable_remotely_by_storedfile($file)) {
            throw new package_exception('missingfile');
        }
        // On local file storage the zip is read in place; other storage needs a temporary copy.
        if ($filesystem->is_file_readable_locally_by_storedfile($file)) {
            return new self($filesystem->get_local_path_from_storedfile($file));
        }
        try {
            $path = $file->copy_content_to_temp('local_chronifycompliance', 'pkg_');
        } catch (\Throwable $e) {
            throw new package_exception('missingfile');
        }
        if (!$path) {
            throw new package_exception('missingfile');
        }
        return new self($path, true);
    }

    /**
     * Build the file index and find the manifest.
     *
     * @throws package_exception
     */
    private function index(): void {
        $count = $this->zip->numFiles;
        if ($count > self::MAX_ENTRIES) {
            throw new package_exception('toomanyfiles', $count);
        }
        $maxbytes = ((int) get_config('local_chronifycompliance', 'maxpackagesize') ?: self::DEFAULT_MAX_MB) * 1048576;
        for ($i = 0; $i < $count; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string) $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!self::is_safe_name($name)) {
                $this->unsafeentries++;
                continue;
            }
            $this->entries[$name] = ['size' => (int) $stat['size'], 'csize' => (int) $stat['comp_size'], 'index' => $i];
            $this->totalbytes += (int) $stat['size'];
            if ($this->totalbytes > $maxbytes) {
                throw new package_exception('toolarge', display_size($maxbytes));
            }
        }

        if (isset($this->entries['imsmanifest.xml'])) {
            $this->root = '';
            return;
        }
        $best = null;
        foreach (array_keys($this->entries) as $name) {
            if (
                str_ends_with(strtolower($name), '/imsmanifest.xml')
                    && ($best === null || substr_count($name, '/') < substr_count($best, '/'))
            ) {
                $best = $name;
            }
        }
        if ($best !== null) {
            $this->root = substr($best, 0, -strlen('imsmanifest.xml'));
        }
    }

    /**
     * Whether a zip entry name is safe to use.
     *
     * @param string $name
     * @return bool
     */
    public static function is_safe_name(string $name): bool {
        if (
            $name === '' || str_contains($name, "\0") || str_contains($name, '\\') || $name[0] === '/'
                || preg_match('~^[a-zA-Z]:~', $name)
        ) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Files relative to the package root (the manifest directory), with their size.
     *
     * @return int[] Size keyed by relative path.
     */
    public function get_files(): array {
        $root = (string) $this->root;
        $files = [];
        foreach ($this->entries as $name => $entry) {
            if ($root === '' || str_starts_with($name, $root)) {
                $files[substr($name, strlen($root))] = $entry['size'];
            }
        }
        return $files;
    }

    /**
     * Whether a file exists, relative to the package root.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool {
        return isset($this->entries[$this->root . $path]);
    }

    /**
     * Read the start of a file, relative to the package root.
     *
     * @param string $path
     * @param int $maxbytes
     * @return string|null Null when the file does not exist.
     */
    public function read(string $path, int $maxbytes = self::MAX_TEXT_BYTES): ?string {
        $entry = $this->entries[$this->root . $path] ?? null;
        if (!$entry) {
            return null;
        }
        $content = $this->zip->getFromIndex($entry['index'], min($maxbytes, max(1, $entry['size'])));
        return $content === false ? null : $content;
    }

    /**
     * Open a read stream on a file, relative to the package root.
     *
     * @param string $path
     * @return resource|null
     */
    public function stream(string $path) {
        $entry = $this->entries[$this->root . $path] ?? null;
        if (!$entry) {
            return null;
        }
        $stream = $this->zip->getStream($this->root . $path);
        return $stream ?: null;
    }

    /**
     * Close the archive and delete the temporary copy.
     */
    public function close(): void {
        try {
            $this->zip->close();
        } catch (\Throwable $e) {
            // Already closed.
            unset($e);
        }
        $this->cleanup();
    }

    /**
     * Delete the temporary copy.
     */
    private function cleanup(): void {
        if ($this->tempfile && file_exists($this->tempfile)) {
            @unlink($this->tempfile);
        }
        $this->tempfile = null;
    }
}
