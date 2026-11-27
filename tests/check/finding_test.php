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

namespace local_completiondoctor\check;

/**
 * Tests for the finding value object.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(finding::class)]
final class finding_test extends \advanced_testcase {
    /**
     * The fingerprint depends on identity only, not on message, severity or details.
     */
    public function test_fingerprint(): void {
        $a = new finding('x', 5, 3, 'msg', ['a' => 1], ['d' => 1], 10, false, ['k' => 1, 'j' => 2]);
        $b = new finding('x', 5, 1, 'other', [], ['d' => 2], 10, true, ['j' => 2, 'k' => 1]);
        $this->assertSame($a->get_fingerprint(), $b->get_fingerprint());
        $this->assertSame(40, strlen($a->get_fingerprint()));

        $this->assertNotSame($a->get_fingerprint(), (new finding('y', 5, 3, 'msg', [], [], 10))->get_fingerprint());
        $this->assertNotSame($a->get_fingerprint(), (new finding(
            'x',
            6,
            3,
            'msg',
            [],
            [],
            10,
            false,
            ['k' => 1, 'j' => 2]
        ))->get_fingerprint());
        $this->assertNotSame($a->get_fingerprint(), (new finding(
            'x',
            5,
            3,
            'msg',
            [],
            [],
            11,
            false,
            ['k' => 1, 'j' => 2]
        ))->get_fingerprint());
        $this->assertNotSame($a->get_fingerprint(), (new finding(
            'x',
            5,
            3,
            'msg',
            [],
            [],
            10,
            false,
            ['k' => 2, 'j' => 2]
        ))->get_fingerprint());
    }

    /**
     * A finding survives a round trip through a database record.
     */
    public function test_record_round_trip(): void {
        $finding = new finding('x', 5, 2, 'pluginname', ['a' => 'b'], ['d' => [1, 2]], null, true, ['k' => 'v']);
        $finding->id = 7;
        $finding->status = finding::STATUS_IGNORED;
        $finding->ignorereason = 'why';
        $finding->ignoredby = 3;
        $finding->timeignored = 100;
        $finding->timecreated = 90;
        $finding->timemodified = 95;
        $finding->lastseen = 99;

        $copy = finding::from_record($finding->to_record());
        $this->assertEquals($finding, $copy);
        $this->assertSame($finding->get_fingerprint(), $copy->get_fingerprint());
    }

    /**
     * Messages use the language string, or fall back to the key when it is missing.
     */
    public function test_get_message(): void {
        $finding = new finding('x', 5, 2, 'pluginname');
        $this->assertSame('Completion Doctor', $finding->get_message());
        $finding = new finding('x', 5, 2, 'nosuchstring');
        $this->assertSame('x: nosuchstring', $finding->get_message());
    }
}
