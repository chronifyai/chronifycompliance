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
 * Finds links in HTML text, PDF files and Office (docx, pptx, xlsx) files.
 *
 * Only absolute http(s) links, links relative to the site root and embedded files
 * (@@PLUGINFILE@@) are returned. Mail, phone, script and in-page links are ignored.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_extractor {
    /** @var int Longest text read from one field. */
    public const MAX_HTML_BYTES = 2 * 1024 * 1024;
    /** @var int Longest link kept. */
    public const MAX_URL_LENGTH = 2048;
    /** @var int Largest decompressed PDF stream that is searched. */
    public const MAX_STREAM_BYTES = 4 * 1024 * 1024;
    /** @var int Total decompressed PDF data searched per file. */
    public const MAX_DECODED_BYTES = 32 * 1024 * 1024;
    /** @var int Largest relationship file read from an Office file. */
    public const MAX_RELS_BYTES = 1024 * 1024;

    /**
     * Links in an HTML or plain text field: link and media attributes, and bare web addresses in the text.
     *
     * @param string|null $html
     * @return string[] Unique links.
     */
    public static function from_html(?string $html): array {
        if ($html === null || trim($html) === '') {
            return [];
        }
        $html = substr($html, 0, self::MAX_HTML_BYTES);
        $urls = [];
        if (preg_match_all('~\b(?:href|src|data|poster)\s*=\s*(["\'])(.*?)\1~is', $html, $matches)) {
            foreach ($matches[2] as $url) {
                $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $text = strip_tags((string) preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('~\bhttps?://[^\s<>"\'()\[\]{}]+~i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = rtrim($url, '.,;:!?');
            }
        }
        return self::normalize($urls);
    }

    /**
     * The text a reader sees for each link of an HTML field: the link text, or the image description.
     *
     * @param string|null $html
     * @return string[] Text keyed by link, as returned by from_html().
     */
    public static function link_texts(?string $html): array {
        if ($html === null || trim($html) === '') {
            return [];
        }
        $html = substr($html, 0, self::MAX_HTML_BYTES);
        $pairs = [];
        if (preg_match_all('~<a\b([^>]*)>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[] = [self::attribute($match[1], 'href'), strip_tags($match[2])];
            }
        }
        if (preg_match_all('~<img\b([^>]*)>~is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[] = [self::attribute($match[1], 'src'), (string) self::attribute($match[1], 'alt')];
            }
        }
        $texts = [];
        foreach ($pairs as [$href, $text]) {
            $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $url = $href === null ? null : (self::normalize([$href])[0] ?? null);
            if ($url !== null && $text !== '' && !isset($texts[$url])) {
                $texts[$url] = \core_text::substr($text, 0, 255);
            }
        }
        return $texts;
    }

    /**
     * Value of a quoted attribute of a tag.
     *
     * @param string $attributes Attribute part of the tag.
     * @param string $name
     * @return string|null Decoded value, null when missing.
     */
    private static function attribute(string $attributes, string $name): ?string {
        if (!preg_match('~(?:^|\s)' . $name . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $match)) {
            return null;
        }
        return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Links in a PDF file (link annotations), including compressed object streams.
     *
     * @param string $content Raw PDF content.
     * @return string[] Unique links.
     */
    public static function from_pdf(string $content): array {
        if (!str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            return [];
        }
        $urls = self::pdf_uris($content);
        $decoded = 0;
        if (preg_match_all('~(?<!end)stream\r?\n~', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$token, $offset]) {
                $start = $offset + strlen($token);
                $end = strpos($content, 'endstream', $start);
                if ($end === false) {
                    break;
                }
                // Only zlib streams can hold annotations we can read; images and fonts fail fast or are too big.
                $inflated = @zlib_decode(substr($content, $start, $end - $start), self::MAX_STREAM_BYTES);
                if ($inflated === false || stripos($inflated, '/URI') === false) {
                    continue;
                }
                $urls = array_merge($urls, self::pdf_uris($inflated));
                $decoded += strlen($inflated);
                if ($decoded > self::MAX_DECODED_BYTES) {
                    break;
                }
            }
        }
        return self::normalize($urls);
    }

    /**
     * Links in an Office Open XML file (docx, pptx, xlsx): external relationships.
     *
     * @param package_reader $reader The opened zip.
     * @return string[] Unique links.
     */
    public static function from_ooxml(package_reader $reader): array {
        $urls = [];
        foreach ($reader->get_files() as $path => $size) {
            if (!str_ends_with((string) $path, '.rels') || $size > self::MAX_RELS_BYTES) {
                continue;
            }
            $xml = $reader->read((string) $path, self::MAX_RELS_BYTES);
            if ($xml === null || !preg_match_all('~<Relationship\b[^>]*>~i', $xml, $matches)) {
                continue;
            }
            foreach ($matches[0] as $tag) {
                if (
                    preg_match('~\bTargetMode\s*=\s*["\']External["\']~i', $tag)
                        && preg_match('~\bTarget\s*=\s*(["\'])(.*?)\1~i', $tag, $target)
                ) {
                    $urls[] = html_entity_decode($target[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
        return self::normalize($urls);
    }

    /**
     * Keep only links that can be checked, without fragments and duplicates.
     *
     * @param string[] $urls
     * @return string[]
     */
    public static function normalize(array $urls): array {
        $result = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            }
            if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || !preg_match('~^(https?://|@@PLUGINFILE@@/|/)~i', $url)) {
                continue;
            }
            $url = (string) preg_replace('~#.*$~s', '', $url);
            if (preg_match('~^https?://$~i', $url) || preg_match('~[\x00-\x1f]~', $url)) {
                continue;
            }
            $result[$url] = true;
        }
        return array_keys($result);
    }

    /**
     * Read the /URI strings of a block of PDF data.
     *
     * @param string $data
     * @return string[]
     */
    private static function pdf_uris(string $data): array {
        $urls = [];
        $offset = 0;
        $length = strlen($data);
        while (($pos = strpos($data, '/URI', $offset)) !== false) {
            $i = $pos + 4;
            while ($i < $length && strpos(" \t\r\n", $data[$i]) !== false) {
                $i++;
            }
            $offset = $i;
            if ($i >= $length) {
                break;
            }
            if ($data[$i] === '(') {
                [$value, $offset] = self::pdf_literal($data, $i + 1);
            } else if ($data[$i] === '<' && ($data[$i + 1] ?? '') !== '<') {
                $close = strpos($data, '>', $i);
                if ($close === false) {
                    break;
                }
                $value = (string) hex2bin(str_pad(
                    preg_replace('~[^0-9a-fA-F]~', '', substr($data, $i + 1, $close - $i - 1)),
                    2,
                    '0'
                ));
                $offset = $close + 1;
            } else {
                continue;
            }
            if (str_starts_with($value, "\xFE\xFF")) {
                $value = (string) \core_text::convert(substr($value, 2), 'UTF-16BE', 'UTF-8');
            }
            $urls[] = $value;
        }
        return $urls;
    }

    /**
     * Decode a PDF literal string starting after its opening parenthesis.
     *
     * @param string $data
     * @param int $i Position after "(".
     * @return array [decoded string, position after the closing parenthesis]
     */
    private static function pdf_literal(string $data, int $i): array {
        $length = strlen($data);
        $depth = 1;
        $value = '';
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];
        while ($i < $length && strlen($value) <= self::MAX_URL_LENGTH) {
            $char = $data[$i++];
            if ($char === '\\' && $i < $length) {
                $next = $data[$i++];
                if (isset($escapes[$next])) {
                    $value .= $escapes[$next];
                } else if (ctype_digit($next) && $next < '8') {
                    $octal = $next;
                    while (strlen($octal) < 3 && $i < $length && ctype_digit($data[$i]) && $data[$i] < '8') {
                        $octal .= $data[$i++];
                    }
                    $value .= chr(octdec($octal) & 0xFF);
                } else if ($next === "\r" || $next === "\n") {
                    if ($next === "\r" && ($data[$i] ?? '') === "\n") {
                        $i++;
                    }
                } else {
                    $value .= $next;
                }
                continue;
            }
            if ($char === '(') {
                $depth++;
            } else if ($char === ')' && --$depth === 0) {
                break;
            }
            $value .= $char;
        }
        return [$value, $i];
    }
}
