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

namespace local_completiondoctor\links;

use local_completiondoctor\tests\fake_http_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../classes/fake_http_client.php');

/**
 * Tests of checking single links, with a fake HTTP client.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(link_checker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(link_store::class)]
final class link_checker_test extends \advanced_testcase {
    /** @var fake_http_client */
    private fake_http_client $http;

    /**
     * A checker with the fake client.
     *
     * @param int $max Maximum external URLs.
     * @return link_checker
     */
    private function checker(int $max = 100): link_checker {
        $this->http = new fake_http_client();
        $this->http->hosts = ['example.com' => true, 'slow.example' => true];
        return new link_checker($this->http, $max, DAYSECS, HOURSECS);
    }

    /**
     * External links: DNS, not found, HEAD refused, blocked, timeout, certificate, access refused.
     */
    public function test_external(): void {
        $this->resetAfterTest();
        $checker = $this->checker();
        $this->http->responses = [
            'HEAD https://example.com/gone' => ['code' => 404],
            'GET https://example.com/gone' => ['code' => 404],
            'HEAD https://example.com/nohead' => ['code' => 405],
            'GET https://example.com/nohead' => ['code' => 206],
            'HEAD https://example.com/blocked' => ['code' => 0, 'blocked' => true],
            'HEAD https://slow.example/' => ['code' => 0, 'errno' => 28],
            'HEAD https://example.com/cert' => ['code' => 0, 'errno' => 60],
            'HEAD https://example.com/private' => ['code' => 403],
            'GET https://example.com/private' => ['code' => 403],
        ];

        $result = $checker->check('https://goglec.com/');
        $this->assertSame(
            [link_checker::KIND_EXTERNAL, link_checker::STATUS_BROKEN, 'dns'],
            [$result['kind'], $result['status'], $result['reason']]
        );
        $this->assertSame([], $this->http->requests, 'A domain that does not exist is never requested.');

        $this->assertSame([link_checker::STATUS_OK, null], $this->state_of($checker->check('https://example.com/ok')));
        $this->assertSame([link_checker::STATUS_BROKEN, 'notfound'], $this->state_of($checker->check('https://example.com/gone')));
        $this->assertSame([link_checker::STATUS_OK, null], $this->state_of($checker->check('https://example.com/nohead')));
        $this->assertSame(
            [link_checker::STATUS_SKIPPED, 'blocked'],
            $this->state_of($checker->check('https://example.com/blocked'))
        );
        $this->assertSame([link_checker::STATUS_UNVERIFIED, 'timeout'], $this->state_of($checker->check('https://slow.example/')));
        $this->assertSame(
            [link_checker::STATUS_UNVERIFIED, 'certificate'],
            $this->state_of($checker->check('https://example.com/cert'))
        );
        $this->assertSame(
            [link_checker::STATUS_UNVERIFIED, 'access'],
            $this->state_of($checker->check('https://example.com/private'))
        );

        $this->assertContains('GET https://example.com/nohead', $this->http->requests);
        $this->assertGreaterThan(0, $this->http->waited, 'Requests to the same host are spaced out.');
    }

    /**
     * Limits and reuse of recent results.
     */
    public function test_limit_and_cache(): void {
        $this->resetAfterTest();
        link_store::replace_course(1, [[
            'cmid' => 0, 'source' => 'summary', 'itemid' => 0, 'sourcename' => null, 'url' => 'https://example.com/known',
            'urlhash' => sha1('https://example.com/known'), 'kind' => link_checker::KIND_EXTERNAL,
            'status' => link_checker::STATUS_BROKEN, 'httpcode' => 404, 'reason' => 'notfound', 'timechecked' => time() - 60,
        ]]);
        $checker = $this->checker(1);
        $result = $checker->check('https://example.com/known');
        $this->assertSame(
            [link_checker::STATUS_BROKEN, 'notfound', time() - 60],
            [$result['status'], $result['reason'], $result['timechecked']]
        );
        $this->assertSame([], $this->http->requests, 'A recent result is reused.');

        $this->assertSame([link_checker::STATUS_OK, null], $this->state_of($checker->check('https://example.com/one')));
        $this->assertSame([link_checker::STATUS_SKIPPED, 'limit'], $this->state_of($checker->check('https://example.com/two')));
        $this->assertCount(1, $this->http->requests);
    }

    /**
     * Links to this site: activities, courses, files, draft files and embedded files.
     */
    public function test_internal(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $deleted = $generator->create_module('page', ['course' => $course->id]);
        // Remove the activity row directly: the deletion API differs between the supported versions.
        $DB->delete_records('course_modules', ['id' => $deleted->cmid]);
        $DB->delete_records('page', ['id' => $deleted->id]);
        rebuild_course_cache($course->id, true);
        $context = \context_module::instance($page->cmid);
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_page',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/img/', 'filename' => 'a b.png'], 'x');
        $area = ['contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0];

        $checker = $this->checker();
        $cases = [
            [$CFG->wwwroot . '/mod/page/view.php?id=' . $page->cmid, null, link_checker::STATUS_OK, null],
            [$CFG->wwwroot . '/mod/page/view.php?id=' . $deleted->cmid, null, link_checker::STATUS_BROKEN, 'cmdeleted'],
            [$CFG->wwwroot . '/course/view.php?id=' . $course->id, null, link_checker::STATUS_OK, null],
            [$CFG->wwwroot . '/course/view.php?id=999999', null, link_checker::STATUS_BROKEN, 'coursedeleted'],
            [$CFG->wwwroot . '/draftfile.php/5/user/draft/1/x.png', null, link_checker::STATUS_BROKEN, 'draftfile'],
            [$CFG->wwwroot . "/pluginfile.php/{$context->id}/mod_page/content/3/img/a%20b.png", null,
                link_checker::STATUS_OK, null],
            [$CFG->wwwroot . "/pluginfile.php/{$context->id}/mod_page/content/3/gone.png", null,
                link_checker::STATUS_BROKEN, 'filemissing'],
            ['@@PLUGINFILE@@/img/a%20b.png', $area, link_checker::STATUS_OK, null],
            ['@@PLUGINFILE@@/img/missing.png', $area, link_checker::STATUS_BROKEN, 'filemissing'],
            [$CFG->wwwroot . '/my/', null, link_checker::STATUS_OK, null],
        ];
        foreach ($cases as [$url, $urlarea, $status, $reason]) {
            $result = $checker->check($url, $urlarea);
            $this->assertSame(link_checker::KIND_INTERNAL, $result['kind'], $url);
            $this->assertSame([$status, $reason], $this->state_of($result), $url);
        }
        $this->assertSame([], $this->http->requests, 'Links to this site are never requested over HTTP.');
    }

    /**
     * Status and reason of a result.
     *
     * @param array $result
     * @return array
     */
    private function state_of(array $result): array {
        return [$result['status'], $result['reason']];
    }
}
