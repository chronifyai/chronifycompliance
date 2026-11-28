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

namespace local_chronifycompliance\links;

use local_chronifycompliance\scorm\package_reader;

/**
 * Collects the links of a course: course and section summaries, activity descriptions, page and
 * book content, lesson pages, glossary entries, question texts, URL activities, and PDF and
 * Office files stored in the course. Forum posts and other learner writing are not read.
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_collector {
    /** @var int Most link occurrences kept per course. */
    public const MAX_OCCURRENCES = 5000;
    /** @var int Most documents read per course. */
    public const MAX_FILES = 200;
    /** @var string[] Document types read, by mime type. */
    public const DOCUMENT_TYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'ooxml',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ooxml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'ooxml',
    ];

    /** @var int Largest document read, bytes. */
    private int $maxfilebytes;
    /** @var array[] Occurrences found. */
    private array $occurrences = [];
    /** @var bool Whether MAX_OCCURRENCES was reached. */
    private bool $truncated = false;
    /** @var int Documents not read because they are too large or unreadable. */
    private int $skippedfiles = 0;
    /** @var int[] Activity id by context id. */
    private array $cmbycontext = [];

    /**
     * Constructor.
     *
     * @param int $maxfilebytes Largest document read.
     */
    public function __construct(int $maxfilebytes) {
        $this->maxfilebytes = $maxfilebytes;
    }

    /**
     * Create a collector with the plugin settings.
     *
     * @return self
     */
    public static function from_config(): self {
        $mb = (int) get_config('local_chronifycompliance', 'linkmaxfilemb') ?: 20;
        return new self($mb * 1048576);
    }

    /**
     * Collect the links of a course.
     *
     * @param \stdClass $course
     * @return array[] Occurrences with cmid, source, itemid, sourcename, url, linktext and area (file area of the text or null).
     */
    public function collect(\stdClass $course): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->occurrences = [];
        $this->truncated = false;
        $this->skippedfiles = 0;
        $this->cmbycontext = [];

        $coursecontext = \context_course::instance($course->id);
        $this->add_text($course->summary ?? '', 0, 'summary', 0, null, $this->area($coursecontext->id, 'course', 'summary', 0));

        $modinfo = get_fast_modinfo($course->id, -1);
        foreach ($modinfo->get_section_info_all() as $section) {
            $this->add_text(
                (string) $section->summary,
                0,
                'section',
                (int) $section->id,
                get_section_name($course, $section),
                $this->area($coursecontext->id, 'course', 'section', (int) $section->id)
            );
        }

        $bymodule = [];
        foreach ($modinfo->get_cms() as $cm) {
            $bymodule[$cm->modname][(int) $cm->instance] = $cm;
            $this->cmbycontext[(int) $cm->context->id] = (int) $cm->id;
        }
        foreach ($bymodule as $modname => $cms) {
            $this->collect_module((string) $modname, $cms);
        }
        $this->collect_questions(array_merge([(int) $coursecontext->id], array_keys($this->cmbycontext)));
        $this->collect_files(array_merge([(int) $coursecontext->id], array_keys($this->cmbycontext)));
        return $this->occurrences;
    }

    /**
     * Whether the occurrence limit was reached.
     *
     * @return bool
     */
    public function is_truncated(): bool {
        return $this->truncated;
    }

    /**
     * Documents that were not read (too large or not readable).
     *
     * @return int
     */
    public function get_skipped_files(): int {
        return $this->skippedfiles;
    }

    /**
     * Texts of one module type.
     *
     * @param string $modname
     * @param \cm_info[] $cms Keyed by instance id.
     */
    private function collect_module(string $modname, array $cms): void {
        global $DB;
        $ids = array_keys($cms);
        $component = 'mod_' . $modname;
        $columns = $DB->get_columns($modname);
        if (isset($columns['intro'])) {
            foreach ($DB->get_records_list($modname, 'id', $ids, '', 'id, intro') as $record) {
                $cm = $cms[(int) $record->id];
                $this->add_text(
                    $record->intro,
                    (int) $cm->id,
                    'intro',
                    0,
                    null,
                    $this->area((int) $cm->context->id, $component, 'intro', 0)
                );
            }
        }
        $children = [
            'page' => [null, 'content', 'content', 'content', null],
            'book' => ['book_chapters', 'bookid', 'content', 'chapter', 'title'],
            'lesson' => ['lesson_pages', 'lessonid', 'contents', 'page', 'title'],
            'glossary' => ['glossary_entries', 'glossaryid', 'definition', 'entry', 'concept'],
        ];
        $areas = ['page' => 'content', 'book' => 'chapter', 'lesson' => 'page_contents', 'glossary' => 'entry'];
        if ($modname === 'url') {
            foreach ($DB->get_records_list('url', 'id', $ids, '', 'id, externalurl') as $record) {
                foreach (link_extractor::normalize([(string) $record->externalurl]) as $url) {
                    $this->add($url, (int) $cms[(int) $record->id]->id, 'url', 0, null, null);
                }
            }
            return;
        }
        if (!isset($children[$modname])) {
            return;
        }
        [$table, $parentfield, $textfield, $source, $titlefield] = $children[$modname];
        if ($table === null) {
            foreach ($DB->get_records_list($modname, 'id', $ids, '', "id, {$textfield}") as $record) {
                $cm = $cms[(int) $record->id];
                $this->add_text(
                    $record->{$textfield},
                    (int) $cm->id,
                    $source,
                    0,
                    null,
                    $this->area((int) $cm->context->id, $component, $areas[$modname], 0)
                );
            }
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $recordset = $DB->get_recordset_select(
            $table,
            "{$parentfield} {$insql}",
            $params,
            'id',
            "id, {$parentfield}, {$titlefield}, {$textfield}"
        );
        foreach ($recordset as $record) {
            $cm = $cms[(int) $record->{$parentfield}] ?? null;
            if ($cm) {
                $this->add_text(
                    $record->{$textfield},
                    (int) $cm->id,
                    $source,
                    (int) $record->id,
                    $record->{$titlefield},
                    $this->area((int) $cm->context->id, $component, $areas[$modname], (int) $record->id)
                );
            }
            if ($this->truncated) {
                break;
            }
        }
        $recordset->close();
    }

    /**
     * Texts of the latest version of the questions stored in the course and its activities.
     *
     * @param int[] $contextids
     */
    private function collect_questions(array $contextids): void {
        global $DB;
        if ($this->truncated) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $sql = "SELECT q.id, q.name, q.questiontext, qc.contextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qc.contextid {$insql}
                   AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                      WHERE v.questionbankentryid = qbe.id)
              ORDER BY q.id";
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $record) {
            $this->add_text(
                $record->questiontext,
                $this->cmbycontext[(int) $record->contextid] ?? 0,
                'question',
                (int) $record->id,
                $record->name,
                $this->area((int) $record->contextid, 'question', 'questiontext', (int) $record->id)
            );
            if ($this->truncated) {
                break;
            }
        }
        $recordset->close();
    }

    /**
     * Links in PDF and Office files stored in the course and its activities.
     *
     * @param int[] $contextids
     */
    private function collect_files(array $contextids): void {
        global $DB;
        if ($this->truncated) {
            return;
        }
        $fs = get_file_storage();
        [$ctxsql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        [$mimesql, $mimeparams] = $DB->get_in_or_equal(array_keys(self::DOCUMENT_TYPES), SQL_PARAMS_NAMED, 'mime');
        $params += $mimeparams + ['dot' => '.', 'backup' => 'backup', 'draft' => 'draft'];
        $recordset = $DB->get_recordset_select(
            'files',
            "contextid {$ctxsql} AND mimetype {$mimesql} AND filename <> :dot AND component <> :backup AND filearea <> :draft",
            $params,
            'id'
        );
        $links = [];
        $count = 0;
        foreach ($recordset as $record) {
            if ($count++ >= self::MAX_FILES) {
                break;
            }
            if (!array_key_exists($record->contenthash, $links)) {
                $links[$record->contenthash] = $this->read_document($fs->get_file_instance($record));
            }
            $cmid = $this->cmbycontext[(int) $record->contextid] ?? 0;
            foreach ($links[$record->contenthash] ?? [] as $url) {
                $this->add($url, $cmid, 'file', (int) $record->id, $record->filename, null);
            }
            if ($this->truncated) {
                break;
            }
        }
        $recordset->close();
    }

    /**
     * Links of one document.
     *
     * @param \stored_file $file
     * @return string[]|null Null when the document could not be read.
     */
    private function read_document(\stored_file $file): ?array {
        $type = self::DOCUMENT_TYPES[$file->get_mimetype()] ?? null;
        if (
            !$type || $file->get_filesize() > $this->maxfilebytes
                || !get_file_storage()->get_file_system()->is_file_readable_remotely_by_storedfile($file)
        ) {
            $this->skippedfiles++;
            return null;
        }
        try {
            if ($type === 'pdf') {
                return link_extractor::from_pdf($file->get_content());
            }
            $reader = package_reader::from_stored_file($file);
            try {
                return link_extractor::from_ooxml($reader);
            } finally {
                $reader->close();
            }
        } catch (\Throwable $e) {
            $this->skippedfiles++;
            return null;
        }
    }

    /**
     * File area of a text, used to check embedded files.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @return array
     */
    private function area(int $contextid, string $component, string $filearea, int $itemid): array {
        return ['contextid' => $contextid, 'component' => $component, 'filearea' => $filearea, 'itemid' => $itemid];
    }

    /**
     * Add the links of a text.
     *
     * @param string|null $text
     * @param int $cmid
     * @param string $source
     * @param int $itemid
     * @param string|null $sourcename
     * @param array|null $area
     */
    private function add_text(?string $text, int $cmid, string $source, int $itemid, ?string $sourcename, ?array $area): void {
        $texts = link_extractor::link_texts($text);
        foreach (link_extractor::from_html($text) as $url) {
            $this->add($url, $cmid, $source, $itemid, $sourcename, $area, $texts[$url] ?? null);
        }
    }

    /**
     * Add one link.
     *
     * @param string $url
     * @param int $cmid
     * @param string $source
     * @param int $itemid
     * @param string|null $sourcename
     * @param array|null $area
     * @param string|null $linktext Text the reader sees for the link.
     */
    private function add(
        string $url,
        int $cmid,
        string $source,
        int $itemid,
        ?string $sourcename,
        ?array $area,
        ?string $linktext = null
    ): void {
        $url = trim($url);
        if ($url === '' || $this->truncated) {
            return;
        }
        if (count($this->occurrences) >= self::MAX_OCCURRENCES) {
            $this->truncated = true;
            return;
        }
        $this->occurrences[] = [
            'cmid' => $cmid,
            'source' => $source,
            'itemid' => $itemid,
            'sourcename' => $sourcename === null ? null : \core_text::substr($sourcename, 0, 255),
            'url' => $url,
            'linktext' => $linktext,
            'area' => $area,
        ];
    }
}
