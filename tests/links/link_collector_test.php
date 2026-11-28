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

use local_chronifycompliance\local\scan_manager;
use local_chronifycompliance\task\check_links;

#[\PHPUnit\Framework\Attributes\CoversClass(link_collector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(link_scan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(check_links::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\output\renderer::class)]
/**
 * Tests of collecting the links of a course and of the link check task.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\links\link_collector
 * @covers \local_chronifycompliance\links\link_scan
 * @covers \local_chronifycompliance\task\check_links
 * @covers \local_chronifycompliance\output\renderer
 */
final class link_collector_test extends \advanced_testcase {
    /**
     * A course with links in many places.
     *
     * @return array [course, page module]
     */
    private function create_course_with_links(): array {
        global $CFG, $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '<a href="https://example.com/summary">x</a>']);
        $deleted = $generator->create_module('page', ['course' => $course->id]);
        // Remove the activity row directly: the deletion API differs between the supported versions.
        $DB->delete_records('course_modules', ['id' => $deleted->cmid]);
        $DB->delete_records('page', ['id' => $deleted->id]);
        rebuild_course_cache($course->id, true);
        $page = $generator->create_module('page', ['course' => $course->id,
            'intro' => '<p>Read https://example.com/intro</p>',
            'content' => '<a href="' . $CFG->wwwroot . '/mod/page/view.php?id=' . $deleted->cmid . '">old</a>'
                . '<img src="@@PLUGINFILE@@/missing.png">']);
        $generator->create_module('url', ['course' => $course->id, 'externalurl' => 'https://example.com/url']);
        $book = $generator->create_module('book', ['course' => $course->id]);
        $generator->get_plugin_generator('mod_book')->create_chapter(['bookid' => $book->id, 'title' => 'Chapter one',
            'content' => '<a href="https://example.com/chapter">c</a>']);
        $glossary = $generator->create_module('glossary', ['course' => $course->id]);
        $generator->get_plugin_generator('mod_glossary')->create_content($glossary, ['concept' => 'Term',
            'definition' => '<a href="https://example.com/entry">e</a>']);
        $resource = $generator->create_module('resource', ['course' => $course->id]);
        get_file_storage()->create_file_from_string(
            ['contextid' => \context_module::instance($resource->cmid)->id,
            'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'guide.pdf', 'mimetype' => 'application/pdf'],
            "%PDF-1.4\n1 0 obj << /A << /S /URI /URI (https://example.com/pdf) >> >> endobj\n%%EOF"
        );
        return [$course, $page];
    }

    /**
     * Every source of a course is read.
     */
    public function test_collect(): void {
        $this->resetAfterTest();
        [$course] = $this->create_course_with_links();
        $collector = new link_collector(1048576);
        $found = [];
        foreach ($collector->collect($course) as $occurrence) {
            $found[$occurrence['source']][] = $occurrence['url'];
        }
        $this->assertSame(['https://example.com/summary'], $found['summary']);
        $this->assertContains('https://example.com/intro', $found['intro']);
        $this->assertCount(2, $found['content']);
        $this->assertSame(['https://example.com/url'], $found['url']);
        $this->assertSame(['https://example.com/chapter'], $found['chapter']);
        $this->assertSame(['https://example.com/entry'], $found['entry']);
        $this->assertSame(['https://example.com/pdf'], $found['file']);
        $this->assertFalse($collector->is_truncated());
        $this->assertSame(0, $collector->get_skipped_files());

        $small = new link_collector(10);
        $small->collect($course);
        $this->assertSame(1, $small->get_skipped_files(), 'Files over the size limit are not read.');
    }

    /**
     * The task stores the result and the progress; recent external results are reused, so no request is made.
     */
    public function test_task(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        // Only the check queued below runs; automatic checks are tested in link_auto_test.
        set_config('linkautonew', 0, 'local_chronifycompliance');
        [$course, $page] = $this->create_course_with_links();
        $urls = ['https://example.com/summary', 'https://example.com/intro', 'https://example.com/url',
            'https://example.com/chapter', 'https://example.com/entry', 'https://example.com/pdf'];
        $other = $this->getDataGenerator()->create_course();
        $rows = [];
        foreach ($urls as $url) {
            $rows[] = ['cmid' => 0, 'source' => 'summary', 'itemid' => 0, 'sourcename' => null, 'url' => $url,
                'urlhash' => sha1($url), 'kind' => link_checker::KIND_EXTERNAL, 'httpcode' => 200, 'reason' => null,
                'status' => link_checker::STATUS_OK, 'timechecked' => time()];
        }
        link_store::replace_course((int) $other->id, $rows);

        $this->assertTrue(link_scan::queue((int) $course->id, 2));
        $this->assertFalse(link_scan::queue((int) $course->id, 2), 'A second check is not queued while one waits.');
        $this->assertSame(scan_manager::STATUS_QUEUED, link_scan::get_state((int) $course->id)->status);
        $this->assertNull(scan_manager::get_active_scan(), 'Link checks are not site scans.');

        ob_start();
        $this->runAdhocTasks(check_links::class);
        ob_end_clean();

        $state = link_scan::get_state((int) $course->id);
        $this->assertSame(scan_manager::STATUS_FINISHED, $state->status);
        $this->assertSame(0, (int) $state->skippedfiles);
        $this->assertSame(0, (int) $state->truncated);
        $this->assertSame((int) $state->totalitems, (int) $state->processed);

        $broken = link_store::get_course_links((int) $course->id, [link_checker::STATUS_BROKEN]);
        $reasons = [];
        foreach ($broken as $link) {
            $reasons[$link->reason] = (int) $link->cmid;
        }
        $this->assertSame(['cmdeleted' => (int) $page->cmid, 'filemissing' => (int) $page->cmid], $reasons);
        $counts = link_store::count_by_status((int) $course->id);
        $this->assertSame(count($urls), $counts[link_checker::STATUS_OK]);
        $this->assertSame(0, $counts[link_checker::STATUS_SKIPPED]);

        // The course report lists the broken links, with no missing strings.
        $PAGE->set_url('/local/chronifycompliance/course.php');
        $PAGE->set_context(\context_system::instance());
        $html = $PAGE->get_renderer('local_chronifycompliance')->course_report(get_course($course->id), 'links');
        $this->assertStringContainsString('id="cd-links"', $html);
        $this->assertStringContainsString(get_string('links:reason:cmdeleted', 'local_chronifycompliance'), $html);
        $this->assertStringContainsString(get_string('links:embedded', 'local_chronifycompliance', 'missing.png'), $html);
        $this->assertStringContainsString('value="checklinks"', $html);
        $this->assertStringContainsString(s(get_string('links:linktext', 'local_chronifycompliance', 'old')), $html);
        $this->assertStringContainsString('lcd-link-location', $html);
        $this->assertStringContainsString('/course/modedit.php?update=' . $page->cmid, $html);
        $this->assertStringNotContainsString('[[', $html);

        // Deleting the course deletes its links.
        delete_course($course, false);
        $this->assertFalse($DB->record_exists(link_store::TABLE, ['courseid' => $course->id]));
    }
}
