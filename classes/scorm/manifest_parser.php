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

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses imsmanifest.xml.
 *
 * Safe parsing: documents declaring entities are rejected before parsing, DTDs are never loaded and
 * the parser never accesses the network. Element and attribute names are matched by local name,
 * so the many namespace variants found in real packages work.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest_parser {
    /**
     * Parse a manifest.
     *
     * @param string $xml
     * @return array Keys: version (SCORM_1.2 or SCORM_1.3), schemaversion, edition, organizations, scos (list),
     *               assetcount, resourcecount, launch, masteryscore, completionthreshold, warnings.
     * @throws package_exception
     */
    public static function parse(string $xml): array {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        if (preg_match('/<!ENTITY/i', $xml)) {
            throw new package_exception('unsafexml');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new package_exception('badmanifest');
        }
        $xpath = new DOMXPath($document);

        $schemaversion = trim((string) $xpath->evaluate("string(//*[local-name()='metadata']/*[local-name()='schemaversion'])"));
        [$version, $edition] = self::detect_version($schemaversion, $xml);

        $resources = [];
        foreach ($xpath->query("//*[local-name()='resources']/*[local-name()='resource']") as $resource) {
            $resources[$resource->getAttribute('identifier')] = [
                'type' => strtolower((string) self::attribute($resource, 'scormtype')),
                'href' => $resource->getAttribute('href'),
            ];
        }

        $scos = [];
        $assetitems = 0;
        $organizations = $xpath->query("//*[local-name()='organizations']/*[local-name()='organization']");
        foreach ($xpath->query("//*[local-name()='organization']//*[local-name()='item'][@identifierref]") as $item) {
            $resource = $resources[$item->getAttribute('identifierref')] ?? null;
            if (!$resource || $resource['type'] !== 'sco') {
                $assetitems++;
                continue;
            }
            $scos[] = [
                'identifier' => $item->getAttribute('identifier'),
                'href' => $resource['href'] . $item->getAttribute('parameters'),
                'masteryscore' => self::number(self::child_text($xpath, $item, 'masteryscore')),
                'completionthreshold' => self::completion_threshold($xpath, $item),
                'objective' => self::primary_objective($xpath, $item),
            ];
        }

        $warnings = [];
        if (!$organizations->length) {
            $warnings[] = 'noorganization';
        }
        if (!$scos) {
            $warnings[] = 'noscos';
        }
        $masteryscores = array_filter(array_column($scos, 'masteryscore'), fn($v) => $v !== null);
        $thresholds = array_filter(array_column($scos, 'completionthreshold'), fn($v) => $v !== null);

        return [
            'version' => $version,
            'schemaversion' => $schemaversion,
            'edition' => $edition,
            'organizations' => $organizations->length,
            'scos' => $scos,
            'assetcount' => count(array_filter($resources, fn($r) => $r['type'] === 'asset')) + $assetitems,
            'resourcecount' => count($resources),
            'launch' => $scos[0]['href'] ?? null,
            'masteryscore' => $masteryscores ? max($masteryscores) : null,
            'completionthreshold' => $thresholds ? max($thresholds) : null,
            'warnings' => $warnings,
        ];
    }

    /**
     * SCORM version and edition from the schema version and namespaces.
     *
     * @param string $schemaversion
     * @param string $xml
     * @return array [version, edition]
     */
    private static function detect_version(string $schemaversion, string $xml): array {
        $value = strtolower($schemaversion);
        if (preg_match('/2004\s*(\d)/', $value, $matches)) {
            return ['SCORM_1.3', '2004 ' . $matches[1] . (['', 'st', 'nd', 'rd'][$matches[1]] ?? 'th') . ' Edition'];
        }
        if (str_contains($value, 'cam 1.3') || $value === '1.3') {
            return ['SCORM_1.3', '2004 2nd Edition'];
        }
        if ($value === '1.2' || str_contains($xml, 'adlcp_rootv1p2')) {
            return ['SCORM_1.2', null];
        }
        if (str_contains($xml, 'adlcp_v1p3') || str_contains($xml, 'imsss')) {
            return ['SCORM_1.3', null];
        }
        return ['SCORM_1.2', null];
    }

    /**
     * Read an attribute by local name, case-insensitively (scormtype vs scormType).
     *
     * @param DOMElement $element
     * @param string $localname
     * @return string|null
     */
    private static function attribute(DOMElement $element, string $localname): ?string {
        foreach ($element->attributes as $attribute) {
            if (strcasecmp($attribute->localName, $localname) === 0) {
                return $attribute->value;
            }
        }
        return null;
    }

    /**
     * Text of a direct child element by local name.
     *
     * @param DOMXPath $xpath
     * @param DOMElement $item
     * @param string $localname
     * @return string|null
     */
    private static function child_text(DOMXPath $xpath, DOMElement $item, string $localname): ?string {
        $nodes = $xpath->query("./*[translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')"
            . "='" . strtolower($localname) . "']", $item);
        return $nodes->length ? trim($nodes->item(0)->textContent) : null;
    }

    /**
     * Completion threshold (2004): element text (3rd edition) or minProgressMeasure (4th edition).
     *
     * @param DOMXPath $xpath
     * @param DOMElement $item
     * @return float|null
     */
    private static function completion_threshold(DOMXPath $xpath, DOMElement $item): ?float {
        $nodes = $xpath->query("./*[local-name()='completionThreshold']", $item);
        if (!$nodes->length) {
            return null;
        }
        /** @var DOMElement $node */
        $node = $nodes->item(0);
        if ($node->hasAttribute('minProgressMeasure')) {
            return self::number($node->getAttribute('minProgressMeasure'));
        }
        return self::number($node->textContent);
    }

    /**
     * Primary objective of the sequencing rules of an item (2004).
     *
     * @param DOMXPath $xpath
     * @param DOMElement $item
     * @return array|null Keys satisfiedbymeasure and minnormalizedmeasure.
     */
    private static function primary_objective(DOMXPath $xpath, DOMElement $item): ?array {
        $nodes = $xpath->query("./*[local-name()='sequencing']/*[local-name()='objectives']"
            . "/*[local-name()='primaryObjective']", $item);
        if (!$nodes->length) {
            return null;
        }
        /** @var DOMElement $objective */
        $objective = $nodes->item(0);
        $measure = $xpath->query("./*[local-name()='minNormalizedMeasure']", $objective);
        return [
            'satisfiedbymeasure' => strtolower($objective->getAttribute('satisfiedByMeasure')) === 'true',
            'minnormalizedmeasure' => $measure->length ? self::number($measure->item(0)->textContent) : null,
        ];
    }

    /**
     * Parse a number.
     *
     * @param string|null $value
     * @return float|null
     */
    private static function number(?string $value): ?float {
        $value = $value === null ? '' : trim($value);
        return is_numeric($value) ? (float) $value : null;
    }
}
