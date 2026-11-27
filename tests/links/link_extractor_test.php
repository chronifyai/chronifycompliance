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

use local_completiondoctor\scorm\package_reader;

/**
 * Tests of finding links in HTML, PDF and Office files.
 *
 * @package    local_completiondoctor
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(link_extractor::class)]
final class link_extractor_test extends \advanced_testcase {
    /**
     * Links in HTML: attributes and bare addresses; mail, script and in-page links are ignored.
     */
    public function test_from_html(): void {
        $html = '<p>See <a href="https://example.com/a?x=1&amp;y=2#part">this</a> and <img src="@@PLUGINFILE@@/pic%20one.png">'
            . ' or https://goglec.com/page. <a href="mailto:a@example.com">mail</a> <a href="#top">top</a>'
            . ' <a href="javascript:void(0)">x</a> <a href="/mod/page/view.php?id=5">page</a>'
            . ' <a href=\'//cdn.example.org/f.js\'>cdn</a> <a href="https://example.com/a?x=1&y=2">dup</a></p>';
        $this->assertSame([
            'https://example.com/a?x=1&y=2',
            '@@PLUGINFILE@@/pic%20one.png',
            '/mod/page/view.php?id=5',
            'https://cdn.example.org/f.js',
            'https://goglec.com/page',
        ], link_extractor::from_html($html));
        $this->assertSame([
            'https://example.com/a?x=1&y=2' => 'this',
            '/mod/page/view.php?id=5' => 'page',
            'https://cdn.example.org/f.js' => 'cdn',
        ], link_extractor::link_texts($html));
        $this->assertSame(
            ['@@PLUGINFILE@@/chart.png' => 'Sales chart'],
            link_extractor::link_texts('<img alt="Sales chart" class="x" src="@@PLUGINFILE@@/chart.png">')
        );
        $this->assertSame([], link_extractor::from_html(null));
        $this->assertSame([], link_extractor::from_html('No links here.'));
    }

    /**
     * Links in a PDF: plain, escaped, hex and inside a compressed object stream.
     */
    public function test_from_pdf(): void {
        $compressed = gzcompress('<< /Type /Annot /A << /S /URI /URI (https://inside.example/stream) >> >>');
        $pdf = "%PDF-1.7\n"
            . "1 0 obj << /A << /S /URI /URI (https://example.com/a\\(1\\)) >> >> endobj\n"
            . "2 0 obj << /A << /URI <68747470733a2f2f6865782e6578616d706c652f> >> >> endobj\n"
            . "3 0 obj << /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n{$compressed}\nendstream\nendobj\n"
            . "4 0 obj << /A << /URI (mailto:a@example.com) >> >> endobj\n%%EOF";
        $this->assertSame([
            'https://example.com/a(1)',
            'https://hex.example/',
            'https://inside.example/stream',
        ], link_extractor::from_pdf($pdf));
        $this->assertSame([], link_extractor::from_pdf('not a pdf /URI (https://example.com/)'));
    }

    /**
     * Links in a Word file: external relationships only.
     */
    public function test_from_ooxml(): void {
        $path = make_request_directory() . '/test.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships>'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'
            . ' Target="https://example.com/doc?a=1&amp;b=2" TargetMode="External"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/></Relationships>');
        $zip->close();
        $reader = new package_reader($path);
        try {
            $this->assertSame(['https://example.com/doc?a=1&b=2'], link_extractor::from_ooxml($reader));
        } finally {
            $reader->close();
        }
    }

    /**
     * Documents made by real tools: Chrome print to PDF, LibreOffice PDF and Word, openpyxl Excel.
     *
     * @return array
     */
    public static function document_provider(): array {
        return [
            'Chrome PDF' => ['handout-chrome.pdf'],
            'LibreOffice PDF' => ['handout-libreoffice.pdf'],
            'Word' => ['handout.docx'],
            'Excel' => ['links.xlsx'],
        ];
    }

    /**
     * Every link of a real document is found.
     *
     * @param string $filename Fixture in tests/fixtures/documents.
     * @dataProvider document_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('document_provider')]
    public function test_real_documents(string $filename): void {
        $path = __DIR__ . '/../fixtures/documents/' . $filename;
        if (str_ends_with($filename, '.pdf')) {
            $urls = link_extractor::from_pdf(file_get_contents($path));
        } else {
            $reader = new package_reader($path);
            try {
                $urls = link_extractor::from_ooxml($reader);
            } finally {
                $reader->close();
            }
        }
        sort($urls);
        $this->assertSame([
            'https://en.wikipedia.org/wiki/Moodle',
            'https://moodle.org/this-page-does-not-exist-404',
            'https://www.gooogleqzx-mistyped.com/search',
        ], $urls);
    }
}
