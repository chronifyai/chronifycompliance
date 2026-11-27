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
 * Tests of the SCORM package analysis, on the synthetic packages in tests/fixtures/packages.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(package_reader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(manifest_parser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tool_detector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(bandwidth_estimator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(media_probe::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(package_analyzer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(stage_detector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(build_detector::class)]
final class analyzer_test extends \advanced_testcase {
    /**
     * Path of a fixture package.
     *
     * @param string $name
     * @return string
     */
    private function fixture(string $name): string {
        return __DIR__ . "/../fixtures/packages/{$name}.zip";
    }

    /**
     * Unsafe entries are skipped; a nested manifest is found; size limits apply.
     */
    public function test_reader_safety(): void {
        $this->resetAfterTest();
        $reader = new package_reader($this->fixture('zipslip'));
        $this->assertSame(1, $reader->unsafeentries);
        $this->assertArrayNotHasKey('../evil.txt', $reader->entries);
        $this->assertSame('', $reader->root);
        $reader->close();

        $reader = new package_reader($this->fixture('nested_manifest'));
        $this->assertSame('Published/', $reader->root);
        $this->assertTrue($reader->exists('index.html'));
        $reader->close();

        set_config('maxpackagesize', 1, 'local_completiondoctor');
        try {
            new package_reader($this->fixture('heavy_media'));
            $this->fail('Size limit not enforced');
        } catch (package_exception $e) {
            $this->assertSame('toolarge', $e->reason);
        }

        foreach (['../a', '/etc/passwd', 'a/../../b', "a\\b", 'C:/x', ''] as $name) {
            $this->assertFalse(package_reader::is_safe_name($name), $name);
        }
        $this->assertTrue(package_reader::is_safe_name('a/b..c/d.html'));
    }

    /**
     * Manifests: versions, SCOs, mastery score, 2004 threshold and objective; entities are refused.
     */
    public function test_manifest_parser(): void {
        $read = function (string $fixture): array {
            $reader = new package_reader($this->fixture($fixture));
            $manifest = manifest_parser::parse($reader->read('imsmanifest.xml'));
            $reader->close();
            return $manifest;
        };
        $single = $read('scorm12_single');
        $this->assertSame('SCORM_1.2', $single['version']);
        $this->assertCount(1, $single['scos']);
        $this->assertSame('index.html', $single['launch']);
        $this->assertNull($single['masteryscore']);

        $this->assertSame(80.0, $read('scorm12_mastery')['masteryscore']);
        $this->assertCount(3, $read('scorm12_multisco')['scos']);

        $scorm2004 = $read('scorm2004_4th');
        $this->assertSame('SCORM_1.3', $scorm2004['version']);
        $this->assertSame('2004 4th Edition', $scorm2004['edition']);
        $this->assertSame(0.75, $scorm2004['completionthreshold']);
        $this->assertSame(['satisfiedbymeasure' => true, 'minnormalizedmeasure' => 0.8], $scorm2004['scos'][0]['objective']);

        $this->expectException(package_exception::class);
        $read('xxe');
    }

    /**
     * Tool profiles detect the synthetic layouts and extract their settings.
     */
    public function test_tool_detector(): void {
        $detect = function (string $fixture): array {
            $reader = new package_reader($this->fixture($fixture));
            $tool = tool_detector::detect($reader);
            $reader->close();
            return $tool;
        };
        $storyline = $detect('storyline_fake');
        $this->assertSame('storyline', $storyline['tool']);
        $this->assertSame('completed-incomplete', $storyline['reportingmode']);
        $this->assertSame([960, 540], [$storyline['stagewidth'], $storyline['stageheight']]);
        $this->assertStringContainsString('Storyline 360', $storyline['version']);
        $this->assertSame(80.0, $storyline['passmark']);

        $rise = $detect('rise_fake');
        $this->assertSame('rise', $rise['tool']);
        $this->assertSame('passed-incomplete', $rise['reportingmode']);
        $this->assertTrue($rise['fluid']);

        $captivate = $detect('captivate_fake');
        $this->assertSame('captivate', $captivate['tool']);
        $this->assertTrue($captivate['experimental']);
        $this->assertSame([1024, 627], [$captivate['stagewidth'], $captivate['stageheight']]);

        $this->assertSame('unknown', $detect('scorm12_single')['tool']);
    }

    /**
     * MP4 duration and MP3 bitrate from streams; the heavy package needs about 12 Mbps.
     */
    public function test_media_and_bandwidth(): void {
        $this->resetAfterTest();
        $mp3 = fopen('php://memory', 'w+');
        // ID3 tag of 4 bytes, then an MPEG-1 layer III frame header at 128 kbps.
        fwrite($mp3, 'ID3' . "\x04\x00\x00\x00\x00\x00\x04" . 'abcd' . "\xFF\xFB\x90\x64" . str_repeat("\0", 100));
        rewind($mp3);
        $this->assertSame(128.0, media_probe::mp3_kbps($mp3));

        $analysis = package_analyzer::analyze_path($this->fixture('heavy_media'));
        $this->assertSame(package_analyzer::STATUS_OK, $analysis['status']);
        $this->assertEqualsWithDelta(8000, $analysis['bandwidth']['maxvideokbps'], 50);
        $this->assertEqualsWithDelta(12.0, $analysis['bandwidth']['minmbps'], 0.2);
        $this->assertSame(2, $analysis['bandwidth']['filesbytype']['video']);
        $this->assertSame(2, $analysis['bandwidth']['mediaprobed']);

        // A file whose duration header sits after more than the read budget is not read to the end.
        $reader = new package_reader($this->fixture('heavy_media'));
        $stream = $reader->stream('media/late.mp4');
        $this->assertNull(media_probe::get_kbps($stream, 'mp4', 3000000));
        fclose($stream);
        $reader->close();
    }

    /**
     * Analyses are cached by content hash and parser version.
     */
    public function test_cache(): void {
        global $DB;
        $this->resetAfterTest();
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id, 'component' => 'local_completiondoctor', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'p.zip',
        ], $this->fixture('storyline_fake'));
        $first = package_analyzer::analyze_stored_file($file);
        $this->assertSame('storyline', $first['tool']['tool']);
        $this->assertSame(1, $DB->count_records(package_analyzer::TABLE));

        $DB->set_field(package_analyzer::TABLE, 'tool', 'cached', []);
        $DB->set_field(package_analyzer::TABLE, 'parserversion', 1, []);
        package_analyzer::analyze_stored_file($file);
        $this->assertSame(1, $DB->count_records(package_analyzer::TABLE), 'Old parser versions are replaced');
        $this->assertSame('storyline', $DB->get_field(package_analyzer::TABLE, 'tool', []));
    }

    /**
     * Nested manifests and non-zips.
     */
    public function test_broken_packages(): void {
        $nested = package_analyzer::analyze_path($this->fixture('nested_manifest'));
        $this->assertTrue($nested['manifest']['nested']);

        $notzip = tempnam(sys_get_temp_dir(), 'cd');
        file_put_contents($notzip, 'not a zip');
        $this->assertSame(package_analyzer::STATUS_UNSUPPORTED, package_analyzer::analyze_path($notzip)['status']);
        unlink($notzip);
    }

    /**
     * Stage fit.
     */
    public function test_stage_detector(): void {
        $stage = ['width' => 960, 'height' => 540, 'fluid' => false];
        $scorm = (object) ['popup' => 0, 'width' => 100, 'height' => 500, 'hidetoc' => 0];
        $keys = array_column(stage_detector::get_issues($stage, $scorm), 'str');
        $this->assertSame(['stage:frameshort', 'stage:sidetoc'], $keys);

        $scorm = (object) ['popup' => 1, 'width' => 800, 'height' => 600, 'hidetoc' => 0];
        $this->assertSame(['stage:popupsmall'], array_column(stage_detector::get_issues($stage, $scorm), 'str'));
        $this->assertSame([], stage_detector::get_issues(['fluid' => true], $scorm));
    }

    /**
     * Language builds sharing one SCO are found and compared.
     */
    public function test_build_detector(): void {
        $diff = package_analyzer::analyze_path($this->fixture('multilang_diff'))['builds'];
        $this->assertTrue($diff['shared']);
        $this->assertSame(['en', 'es'], array_column($diff['builds'], 'language'));
        $keys = array_column($diff['differences'], 'str');
        $this->assertContains('build:diff:version', $keys);
        $this->assertContains('build:diff:slides', $keys);
        $this->assertContains('build:diff:variables', $keys);
        $this->assertContains('build:diff:slideids', $keys);
        $this->assertContains('build:diff:structure', $keys);
        $this->assertSame(2, $diff['slidesdiffer']);

        $same = package_analyzer::analyze_path($this->fixture('multilang_same'))['builds'];
        $this->assertTrue($same['shared']);
        $this->assertSame([], $same['differences']);

        $single = package_analyzer::analyze_path($this->fixture('storyline_fake'))['builds'];
        $this->assertFalse($single['shared']);

        $this->assertSame('pt-br', build_detector::guess_language('scos/Course_pt-BR/'));
        $this->assertNull(build_detector::guess_language('Published/'));
    }

    /**
     * The driver setting that disables the SCORM 1.2 suspend data limit is read.
     */
    public function test_strict_suspend(): void {
        $this->assertFalse(package_analyzer::analyze_path($this->fixture('rise_fake'))['strictsuspend']);
        $this->assertNull(package_analyzer::analyze_path($this->fixture('scorm12_single'))['strictsuspend']);
    }
}
