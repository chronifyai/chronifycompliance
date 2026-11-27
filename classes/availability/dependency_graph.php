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

namespace local_completiondoctor\availability;

use local_completiondoctor\check\audit_context;

/**
 * Graph of "activity A needs activity B to be complete" dependencies in a course, built from the
 * availability of each activity and of its section, and cycle detection on it.
 *
 * Only mandatory dependencies are used: conditions under an AND (or a single-child OR) from the root.
 * Optional (OR) and negated conditions cannot cause a dead lock on their own.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dependency_graph {
    /**
     * Mandatory completion dependencies of every activity.
     *
     * @param audit_context $context
     * @return int[][] cmid => list of cmids it depends on.
     */
    public static function build(audit_context $context): array {
        return $context->remember('dependencygraph', function (audit_context $context): array {
            $modinfo = $context->get_modinfo();
            $sectionrefs = [];
            foreach ($modinfo->get_section_info_all() as $section) {
                $sectionrefs[$section->id] = self::mandatory_refs(
                    $context,
                    $section->availability,
                    null,
                    (int) $section->id
                );
            }
            $graph = [];
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->deletioninprogress) {
                    continue;
                }
                $refs = array_merge(
                    self::mandatory_refs($context, $cm->availability, (int) $cm->id, null),
                    $sectionrefs[$cm->section] ?? []
                );
                $graph[(int) $cm->id] = array_values(array_unique($refs));
            }
            return $graph;
        });
    }

    /**
     * Strongly connected components with more than one node, or with a self dependency.
     *
     * @param int[][] $graph
     * @return int[][] Each cycle as a sorted list of cmids.
     */
    public static function find_cycles(array $graph): array {
        $index = 0;
        $stack = [];
        $onstack = [];
        $indices = [];
        $lowlinks = [];
        $cycles = [];

        $strongconnect = function (int $node) use (
            &$strongconnect,
            &$index,
            &$stack,
            &$onstack,
            &$indices,
            &$lowlinks,
            &$cycles,
            $graph
): void {
            $indices[$node] = $index;
            $lowlinks[$node] = $index;
            $index++;
            $stack[] = $node;
            $onstack[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($graph[$next])) {
                    continue;
                }
                if (!isset($indices[$next])) {
                    $strongconnect($next);
                    $lowlinks[$node] = min($lowlinks[$node], $lowlinks[$next]);
                } else if (!empty($onstack[$next])) {
                    $lowlinks[$node] = min($lowlinks[$node], $indices[$next]);
                }
            }
            if ($lowlinks[$node] === $indices[$node]) {
                $component = [];
                do {
                    $member = array_pop($stack);
                    $onstack[$member] = false;
                    $component[] = $member;
                } while ($member !== $node);
                if (count($component) > 1 || in_array($node, $graph[$node] ?? [], true)) {
                    sort($component);
                    $cycles[] = $component;
                }
            }
        };

        foreach (array_keys($graph) as $node) {
            if (!isset($indices[$node])) {
                $strongconnect($node);
            }
        }
        return $cycles;
    }

    /**
     * The activity core uses for "previous activity with completion" (cm = -1).
     *
     * Mirrors availability_completion: the closest earlier activity with completion tracking; for a
     * section, the last one before the first activity of the section.
     *
     * @param audit_context $context
     * @param int|null $cmid
     * @param int|null $sectionid
     * @return int|null
     */
    public static function get_previous_cmid(audit_context $context, ?int $cmid, ?int $sectionid): ?int {
        [$modprevious, $sectionprevious] = $context->remember('previouscms', function (audit_context $context): array {
            $modprevious = [];
            $sectionprevious = [];
            $lastcmid = 0;
            foreach ($context->get_modinfo()->get_cms() as $cm) {
                if ($cm->deletioninprogress) {
                    continue;
                }
                if (!isset($sectionprevious[$cm->section])) {
                    $sectionprevious[$cm->section] = $lastcmid;
                }
                if ($lastcmid) {
                    $modprevious[$cm->id] = $lastcmid;
                }
                if ($cm->completion != COMPLETION_TRACKING_NONE) {
                    $lastcmid = $cm->id;
                }
            }
            // Empty sections inherit the previous activity of the next non-empty section, or the last one.
            $next = $lastcmid;
            foreach (array_reverse($context->get_modinfo()->get_section_info_all()) as $section) {
                if (isset($sectionprevious[$section->id])) {
                    $next = $sectionprevious[$section->id];
                } else {
                    $sectionprevious[$section->id] = $next;
                }
            }
            return [$modprevious, $sectionprevious];
        });
        if ($cmid !== null) {
            return $modprevious[$cmid] ?? null;
        }
        if ($sectionid !== null && !empty($sectionprevious[$sectionid])) {
            return (int) $sectionprevious[$sectionid];
        }
        return null;
    }

    /**
     * Course modules whose completion is mandatory in an availability tree.
     *
     * @param audit_context $context
     * @param string|null $json
     * @param int|null $cmid
     * @param int|null $sectionid
     * @return int[]
     */
    private static function mandatory_refs(audit_context $context, ?string $json, ?int $cmid, ?int $sectionid): array {
        if (!$json) {
            return [];
        }
        $tree = json_decode($json);
        return is_object($tree) ? self::collect($context, $tree, $cmid, $sectionid) : [];
    }

    /**
     * Collect mandatory completion references of a node.
     *
     * @param audit_context $context
     * @param object $node
     * @param int|null $cmid
     * @param int|null $sectionid
     * @return int[]
     */
    private static function collect(audit_context $context, object $node, ?int $cmid, ?int $sectionid): array {
        if (isset($node->op)) {
            $children = isset($node->c) && is_array($node->c) ? $node->c : [];
            if ($node->op === '&' || ($node->op === '|' && count($children) === 1)) {
                $refs = [];
                foreach ($children as $child) {
                    if (is_object($child)) {
                        $refs = array_merge($refs, self::collect($context, $child, $cmid, $sectionid));
                    }
                }
                return $refs;
            }
            return [];
        }
        if (($node->type ?? '') !== 'completion' || !isset($node->cm)) {
            return [];
        }
        $expected = (int) ($node->e ?? COMPLETION_COMPLETE);
        if ($expected === COMPLETION_INCOMPLETE) {
            return [];
        }
        $ref = (int) $node->cm;
        if ($ref === -1) {
            $ref = self::get_previous_cmid($context, $cmid, $sectionid);
        }
        return $ref ? [$ref] : [];
    }
}
