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

use local_chronifycompliance\persistent\package_record;

/**
 * Analyzes SCORM package files and caches the result by content hash, so a package used by many
 * activities is analyzed once. Raise PARSER_VERSION when the analysis changes; old rows are replaced.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_analyzer {
    /** @var int Version of the analysis logic. */
    public const PARSER_VERSION = 2026101606;
    /** @var string Cache table. */
    public const TABLE = package_record::TABLE;
    /** @var int Analyses that did not finish (fatal error, time-out) before a package is given up. */
    public const MAX_ATTEMPTS = 3;

    /** @var string Analysis succeeded. */
    public const STATUS_OK = 'ok';
    /** @var string Not a SCORM package this plugin can read (AICC, external, no manifest). */
    public const STATUS_UNSUPPORTED = 'unsupported';
    /** @var string The package could not be read. */
    public const STATUS_ERROR = 'error';
    /** @var string The file content is missing from the file system. */
    public const STATUS_MISSINGFILE = 'missingfile';
    /** @var string Waiting in the analysis queue (placeholder row). */
    public const STATUS_QUEUED = 'queued';
    /** @var string Not analyzed yet: queued (returned by inspector, never stored). */
    public const STATUS_PENDING = 'pending';
    /** @var int Default largest package analyzed during an audit (MB); bigger ones are queued. */
    public const DEFAULT_INLINE_MB = 10;

    /**
     * Package file of a SCORM activity.
     *
     * @param \context_module $context
     * @return \stored_file|null
     */
    public static function get_package_file(\context_module $context): ?\stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'mod_scorm', 'package', 0, 'itemid, filepath, filename', false);
        return $files ? reset($files) : null;
    }

    /**
     * Analyze a stored package, using the cache.
     *
     * A stored analysis holds a lock per package, so the same package is never read twice at the same
     * time (the queue task and an "Analyze now" request); the second one gets the pending status.
     *
     * @param \stored_file $file
     * @param bool $refresh Ignore the cache.
     * @param bool $store Store the analysis (otherwise a dry run that writes nothing).
     * @return array Analysis, see analyze_reader().
     */
    public static function analyze_stored_file(\stored_file $file, bool $refresh = false, bool $store = true): array {
        $hash = $file->get_contenthash();
        if (!$refresh) {
            $cached = self::get_cached($hash);
            if ($cached) {
                return $cached;
            }
        }
        if (!$store) {
            return self::analyze_file($file);
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_chronifycompliance')->get_lock('package_' . $hash, 0);
        if (!$lock) {
            return self::pending($hash);
        }
        try {
            return self::analyze_and_store($file, $refresh);
        } finally {
            $lock->release();
        }
    }

    /**
     * Analyze a package and store the result. The caller holds the lock of the package.
     *
     * @param \stored_file $file
     * @param bool $refresh Analyze again even when another process stored an analysis meanwhile.
     * @return array
     */
    private static function analyze_and_store(\stored_file $file, bool $refresh): array {
        $hash = $file->get_contenthash();
        if (!$refresh) {
            $cached = self::get_cached($hash);
            if ($cached) {
                return $cached;
            }
        }
        // Counted before the work starts: a fatal error or time-out leaves the attempt counted.
        self::start_attempt($hash, (int) $file->get_filesize());
        $analysis = self::analyze_file($file);
        if (($analysis['status'] ?? '') === self::STATUS_MISSINGFILE) {
            // Not cached: the file may come back. Drop the placeholder so the queue does not stall.
            self::forget($hash);
        } else {
            self::store($analysis);
        }
        $record = package_record::get_record(['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION]);
        \local_chronifycompliance\event\package_analyzed::create(['context' => \context_system::instance(),
            'objectid' => $record ? (int) $record->get('id') : 0,
            'other' => ['contenthash' => $hash, 'status' => $analysis['status']]])->trigger();
        return $analysis;
    }

    /**
     * Read and analyze a package file, without the cache.
     *
     * @param \stored_file $file
     * @return array
     */
    private static function analyze_file(\stored_file $file): array {
        $hash = $file->get_contenthash();
        try {
            $reader = package_reader::from_stored_file($file);
            try {
                return self::analyze_reader($reader, $hash, (int) $file->get_filesize());
            } finally {
                $reader->close();
            }
        } catch (package_exception $e) {
            return self::failure($e, $hash, (int) $file->get_filesize());
        } catch (\Throwable $e) {
            return self::failure(new package_exception('unreadable', $e->getMessage()), $hash, (int) $file->get_filesize());
        }
    }

    /**
     * The result of a package that is waiting for analysis.
     *
     * @param string $hash
     * @return array
     */
    private static function pending(string $hash): array {
        return ['status' => self::STATUS_PENDING, 'error' => 'queued', 'contenthash' => $hash];
    }

    /**
     * Cached analysis of a package, if any.
     *
     * @param string $hash Content hash.
     * @return array|null Null when not analyzed yet (or only queued).
     */
    public static function get_cached(string $hash): ?array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION]);
        if (!$record || $record->status === self::STATUS_QUEUED || !$record->analysis) {
            return null;
        }
        return json_decode($record->analysis, true);
    }

    /**
     * Analysis for an audit: cached, analyzed now when small, otherwise queued and reported as pending.
     *
     * @param \stored_file $file
     * @param bool $store Store the analysis or the queue row (otherwise a dry run that writes nothing).
     * @return array
     */
    public static function analyze_or_queue(\stored_file $file, bool $store = true): array {
        $cached = self::get_cached($file->get_contenthash());
        if ($cached) {
            return $cached;
        }
        if ($file->get_filesize() <= self::get_inline_bytes()) {
            return self::analyze_stored_file($file, false, $store);
        }
        if ($store) {
            self::queue($file->get_contenthash(), (int) $file->get_filesize());
        }
        return self::pending($file->get_contenthash());
    }

    /**
     * Largest package analyzed inside an audit, in bytes.
     *
     * @return int
     */
    public static function get_inline_bytes(): int {
        $inlinemb = get_config('local_chronifycompliance', 'inlineanalysismb');
        return ($inlinemb === false || $inlinemb === '' ? self::DEFAULT_INLINE_MB : (int) $inlinemb) * 1048576;
    }

    /**
     * Put a package in the analysis queue (a placeholder row until the queue task analyzes it).
     *
     * @param string $hash
     * @param int $filesize
     */
    public static function queue(string $hash, int $filesize = 0): void {
        if (
            package_record::record_exists_select(
                'contenthash = :hash AND parserversion = :version',
                ['hash' => $hash, 'version' => self::PARSER_VERSION]
            )
        ) {
            return;
        }
        self::save_new((object) ['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION,
            'status' => self::STATUS_QUEUED, 'filesize' => $filesize]);
    }

    /**
     * Count an analysis attempt of a package (creating its queue row when missing).
     *
     * @param string $hash
     * @param int $filesize
     */
    private static function start_attempt(string $hash, int $filesize): void {
        $record = package_record::get_record(['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION]);
        if (!$record) {
            self::queue($hash, $filesize);
            $record = package_record::get_record(['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION]);
        }
        if ($record) {
            $record->set('attempts', (int) $record->get('attempts') + 1)->update();
        }
    }

    /**
     * Forget a package (missing file).
     *
     * @param string $hash
     */
    private static function forget(string $hash): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['contenthash' => $hash, 'parserversion' => self::PARSER_VERSION]);
    }

    /**
     * Give up a package whose analysis never finished: store it as an error, so it leaves the queue.
     *
     * @param string $hash
     * @param int $filesize
     */
    public static function give_up(string $hash, int $filesize): void {
        self::store(self::failure(new package_exception('unfinished', self::MAX_ATTEMPTS), $hash, $filesize));
    }

    /**
     * Insert a row, or update the row another request inserted at the same moment.
     *
     * @param \stdClass $data
     */
    private static function save_new(\stdClass $data): void {
        try {
            (new package_record(0, $data))->create();
        } catch (\dml_write_exception $e) {
            $record = package_record::get_record(['contenthash' => $data->contenthash, 'parserversion' => $data->parserversion]);
            if (!$record) {
                throw $e;
            }
            if (($data->status ?? '') !== self::STATUS_QUEUED) {
                $record->from_record($data)->update();
            }
        }
    }

    /**
     * Packages waiting in the queue.
     *
     * @return int
     */
    public static function count_queued(): int {
        global $DB;
        return $DB->count_records(self::TABLE, ['status' => self::STATUS_QUEUED, 'parserversion' => self::PARSER_VERSION]);
    }

    /**
     * SCORM package files of a course that are not analyzed yet (no analysis, or still queued), with
     * their activity. Missing files are left out: they can never be analyzed.
     *
     * @param int $courseid
     * @return \stdClass[] Records with cmid, contenthash and filesize.
     */
    public static function get_waiting(int $courseid): array {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT cm.id AS cmid, f.contenthash, f.filesize, f.id AS fileid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :level
               JOIN {files} f ON f.contextid = ctx.id AND f.component = 'mod_scorm' AND f.filearea = 'package'
                             AND f.filename <> '.'
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0",
            ['level' => CONTEXT_MODULE, 'courseid' => $courseid]
        );
        if (!$records) {
            return [];
        }
        // The analyses are read by hash in a second query: joining text columns of a plugin table and a
        // core table fails on sites whose tables have different collations.
        [$insql, $params] = $DB->get_in_or_equal(array_unique(array_column($records, 'contenthash')), SQL_PARAMS_NAMED);
        $done = $DB->get_records_select_menu(
            self::TABLE,
            "parserversion = :version AND status <> :queued AND contenthash {$insql}",
            $params + ['version' => self::PARSER_VERSION, 'queued' => self::STATUS_QUEUED],
            '',
            'contenthash, id'
        );
        $records = array_filter($records, fn($record) => !isset($done[$record->contenthash]));
        $fs = get_file_storage();
        return array_filter($records, function ($record) use ($fs): bool {
            $file = $fs->get_file_by_id($record->fileid);
            return $file && $fs->get_file_system()->is_file_readable_remotely_by_storedfile($file);
        });
    }

    /**
     * Number of SCORM packages of a course not analyzed yet.
     *
     * @param int $courseid
     * @return int
     */
    public static function count_waiting(int $courseid): int {
        return count(self::get_waiting($courseid));
    }

    /**
     * A SCORM package file with this content hash.
     *
     * @param string $hash
     * @return \stored_file|null
     */
    public static function get_file_by_hash(string $hash): ?\stored_file {
        global $DB;
        $records = $DB->get_records_select(
            'files',
            "contenthash = :hash AND component = 'mod_scorm' AND filearea = 'package' AND filename <> '.'",
            ['hash' => $hash],
            'id',
            '*',
            0,
            1
        );
        return $records ? get_file_storage()->get_file_instance(reset($records)) : null;
    }

    /**
     * Distinct content hashes of all SCORM packages.
     *
     * @return string[]
     */
    public static function get_all_hashes(): array {
        global $DB;
        return $DB->get_fieldset_sql(
            "SELECT DISTINCT contenthash FROM {files}
              WHERE component = 'mod_scorm' AND filearea = 'package' AND filename <> '.'
           ORDER BY contenthash"
        );
    }

    /**
     * Courses using a package.
     *
     * @param string $hash
     * @return int[]
     */
    public static function get_courses_using(string $hash): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT cm.course
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :level
               JOIN {course_modules} cm ON cm.id = ctx.instanceid
              WHERE f.contenthash = :hash AND f.component = 'mod_scorm' AND f.filearea = 'package'",
            ['level' => CONTEXT_MODULE, 'hash' => $hash]
        ));
    }

    /**
     * Analyze a zip file on disk, without caching (CLI).
     *
     * @param string $path
     * @return array
     */
    public static function analyze_path(string $path): array {
        $hash = sha1_file($path) ?: '';
        try {
            $reader = new package_reader($path);
        } catch (package_exception $e) {
            return self::failure($e, $hash, (int) filesize($path));
        }
        try {
            return self::analyze_reader($reader, $hash, (int) filesize($path));
        } catch (package_exception $e) {
            return self::failure($e, $hash, (int) filesize($path));
        } finally {
            $reader->close();
        }
    }

    /**
     * Analyze an open package.
     *
     * @param package_reader $reader
     * @param string $hash
     * @param int $filesize
     * @return array Keys: status, error, contenthash, filesize, parserversion, manifest, tool, stage, bandwidth,
     *               unsafeentries.
     */
    public static function analyze_reader(package_reader $reader, string $hash, int $filesize): array {
        $analysis = [
            'status' => self::STATUS_OK,
            'error' => null,
            'contenthash' => $hash,
            'filesize' => $filesize,
            'parserversion' => self::PARSER_VERSION,
            'unsafeentries' => $reader->unsafeentries,
            'manifest' => null,
            'tool' => null,
            'stage' => null,
            'bandwidth' => null,
            'builds' => null,
            'strictsuspend' => null,
        ];
        if ($reader->root === null) {
            $analysis['status'] = self::STATUS_UNSUPPORTED;
            $analysis['error'] = 'nomanifest';
        } else {
            $manifest = manifest_parser::parse((string) $reader->read('imsmanifest.xml'));
            $manifest['scocount'] = count($manifest['scos']);
            $manifest['nested'] = $reader->root !== '';
            $manifest['root'] = $reader->root;
            $analysis['manifest'] = $manifest;
        }

        $tool = tool_detector::detect($reader);
        $profile = $tool['profile'];
        unset($tool['profile']);
        $analysis['tool'] = $tool;
        $analysis['stage'] = [
            'width' => $tool['stagewidth'],
            'height' => $tool['stageheight'],
            'fluid' => $tool['fluid'],
        ];
        $analysis['bandwidth'] = bandwidth_estimator::estimate($reader, $analysis['manifest']['launch'] ?? null, $profile);
        $analysis['builds'] = build_detector::detect($reader, (int) ($analysis['manifest']['scocount'] ?? 0));
        $analysis['strictsuspend'] = self::detect_strict_suspend($reader);

        // A wrapper page in front of several builds: describe the tool and the load of one build,
        // which is what a learner downloads.
        if ($tool['tool'] === 'unknown' && !empty($analysis['builds']['shared'])) {
            $first = $analysis['builds']['builds'][0];
            $root = $reader->root;
            $reader->root = $root . $first['path'];
            try {
                $buildtool = tool_detector::detect($reader);
                $buildprofile = $buildtool['profile'];
                unset($buildtool['profile']);
                if ($buildtool['tool'] !== 'unknown') {
                    $buildtool['wrapper'] = true;
                    $analysis['tool'] = $buildtool;
                    $analysis['stage'] = ['width' => $buildtool['stagewidth'], 'height' => $buildtool['stageheight'],
                        'fluid' => $buildtool['fluid']];
                }
                $buildbandwidth = bandwidth_estimator::estimate($reader, null, $buildprofile);
            } finally {
                $reader->root = $root;
            }
            foreach (
                ['startupbytes', 'startupfiles', 'maxslidebytes', 'maxslidefile', 'maxvideokbps', 'mediaprobed', 'minmbps',
                    'startupseconds3mbps'] as $key
            ) {
                $analysis['bandwidth'][$key] = $buildbandwidth[$key];
            }
        }
        return $analysis;
    }

    /**
     * Whether the Rustici SCORM driver used by Articulate and other tools enforces the suspend_data
     * size limit of the SCORM standard (USE_STRICT_SUSPEND_DATA_LIMITS).
     *
     * @param package_reader $reader
     * @return bool|null Null when the package has no such driver setting.
     */
    private static function detect_strict_suspend(package_reader $reader): ?bool {
        $checked = 0;
        foreach (array_keys($reader->get_files()) as $path) {
            $name = strtolower(basename((string) $path));
            if (!in_array($name, ['driveroptions.js', 'configuration.js'], true)) {
                continue;
            }
            $text = (string) $reader->read((string) $path, 262144);
            if (preg_match('~USE_STRICT_SUSPEND_DATA_LIMITS\s*=\s*(true|false)~i', $text, $matches)) {
                return strtolower($matches[1]) === 'true';
            }
            if (++$checked >= 10) {
                break;
            }
        }
        return null;
    }

    /**
     * Analysis of a package that could not be read.
     *
     * @param package_exception $e
     * @param string $hash
     * @param int $filesize
     * @return array
     */
    private static function failure(package_exception $e, string $hash, int $filesize): array {
        $status = $e->reason === 'missingfile' ? self::STATUS_MISSINGFILE
            : ($e->reason === 'notzip' ? self::STATUS_UNSUPPORTED : self::STATUS_ERROR);
        return [
            'status' => $status,
            'error' => $e->reason,
            'contenthash' => $hash,
            'filesize' => $filesize,
            'parserversion' => self::PARSER_VERSION,
            'unsafeentries' => 0,
            'manifest' => null,
            'tool' => null,
            'stage' => null,
            'bandwidth' => null,
            'builds' => null,
            'strictsuspend' => null,
        ];
    }

    /**
     * Store an analysis in the cache table, replacing older parser versions.
     *
     * @param array $analysis
     */
    private static function store(array $analysis): void {
        global $DB;
        $manifest = $analysis['manifest'] ?? [];
        $tool = $analysis['tool'] ?? [];
        $bandwidth = $analysis['bandwidth'] ?? [];
        $json = json_encode($analysis, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $analysis = self::failure(
                new package_exception('unreadable', json_last_error_msg()),
                $analysis['contenthash'],
                (int) $analysis['filesize']
            );
            $json = json_encode($analysis, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $data = (object) [
            'contenthash' => $analysis['contenthash'],
            'parserversion' => self::PARSER_VERSION,
            'status' => $analysis['status'],
            'error' => $analysis['error'],
            'filesize' => $analysis['filesize'],
            'scormversion' => $manifest['version'] ?? null,
            'scocount' => $manifest['scocount'] ?? null,
            'assetcount' => $manifest['assetcount'] ?? null,
            'tool' => $tool['tool'] ?? null,
            'toolversion' => isset($tool['version']) ? \core_text::substr((string) $tool['version'], 0, 64) : null,
            'toolconfidence' => $tool['confidence'] ?? null,
            'reportingmode' => $tool['reportingmode'] ?? null,
            'masteryscore' => $manifest['masteryscore'] ?? null,
            'completionthreshold' => $manifest['completionthreshold'] ?? null,
            'stagewidth' => $analysis['stage']['width'] ?? null,
            'stageheight' => $analysis['stage']['height'] ?? null,
            'fluid' => isset($analysis['stage']['fluid']) ? (int) $analysis['stage']['fluid'] : null,
            'totalbytes' => $bandwidth['totalbytes'] ?? null,
            'startupbytes' => $bandwidth['startupbytes'] ?? null,
            'maxslidebytes' => $bandwidth['maxslidebytes'] ?? null,
            'filecount' => $bandwidth['filecount'] ?? null,
            'maxvideokbps' => $bandwidth['maxvideokbps'] ?? null,
            'minmbps' => $bandwidth['minmbps'] ?? null,
            'analysis' => $json,
            'attempts' => 0,
        ];
        $DB->delete_records_select(
            self::TABLE,
            'contenthash = :hash AND parserversion <> :version',
            ['hash' => $analysis['contenthash'], 'version' => self::PARSER_VERSION]
        );
        $existing = package_record::get_record([
            'contenthash' => $analysis['contenthash'],
            'parserversion' => self::PARSER_VERSION,
        ]);
        if ($existing) {
            $existing->from_record($data)->update();
        } else {
            self::save_new($data);
        }
    }
}
