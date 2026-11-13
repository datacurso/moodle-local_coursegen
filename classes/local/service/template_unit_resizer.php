<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


namespace local_coursegen\local\service;

/**
 * Makes the units of a flexsections template as many as the professor asked for.
 *
 * The AI service keeps the structure it is given: it never adds or removes a
 * section. So the plugin resizes the list before it is sent. A unit is a top
 * section with children. When more units are wanted, the model unit (the LAST
 * unit of the template, which is the most regular one: the first usually
 * follows an introduction) is cloned, with its whole subtree, right after the
 * last unit, so the sections that close the course (a final assessment, say)
 * stay last. When fewer are wanted, whole units are dropped from the end, never
 * fewer than one. Everything is renumbered depth-first and the parents
 * rewritten; each entry says which section of the template it comes from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_unit_resizer {
    /** The most units a course is made of; a larger request is clamped to it. */
    public const MAX_UNITS = 50;

    /**
     * The sections with the wanted number of units.
     *
     * @param array $entries The payload's sections_info, in numbering order.
     * @param int $units How many units the course has to hold, clamped to 1..MAX_UNITS.
     * @return array The entries unchanged when nothing has to change, else the resized ones.
     */
    public static function resize(array $entries, int $units): array {
        $tree = template_section_tree::from_entries($entries);
        $current = $tree->units();
        $units = min(self::MAX_UNITS, max(1, $units));
        if (!$current || $units === count($current)) {
            return $entries;
        }

        $planned = self::plan($tree, $entries, $current, $units);
        return self::renumber($tree, $entries, $planned);
    }

    /**
     * The structure the plugin sent, put back on what the service answered.
     *
     * The service keeps the sections it was given but answers its own entry
     * for each one, which need not carry what only the plugin knows: the
     * parent, how deep the section is, and which template section it comes
     * from. The payload the plugin stored is the authority for all of it.
     *
     * @param array $answered The sections_info of the service's result.
     * @param array $sent The sections_info of the stored payload.
     * @return array The answered entries, with the structure of the sent ones.
     */
    public static function with_structure_of(array $answered, array $sent): array {
        $sentbynumber = [];
        foreach ($sent as $entry) {
            $sentbynumber[(int) $entry['section']] = $entry;
        }
        if ($sent && count($answered) !== count($sent)) {
            debugging('The service answered ' . count($answered) . ' sections for the ' . count($sent) .
                ' that were sent; the stored structure is kept by section number.', DEBUG_DEVELOPER);
        }
        foreach ($answered as $index => $entry) {
            $original = $sentbynumber[(int) $entry['section']] ?? null;
            if ($original === null && $sent) {
                debugging('The service answered section ' . (int) $entry['section'] .
                    ', which was not sent; it keeps the structure it came with.', DEBUG_DEVELOPER);
            }
            if ($original === null || !isset($original['parent_section'])) {
                continue;
            }
            foreach (['parent_section', 'depth', 'source_section', 'cloned'] as $key) {
                if (array_key_exists($key, $original)) {
                    $entry[$key] = $original[$key];
                }
            }
            if (!isset($entry['format_options']) && isset($original['format_options'])) {
                $entry['format_options'] = $original['format_options'];
            }
            if (isset($entry['format_options']) && is_array($entry['format_options'])) {
                $entry['format_options']['parent'] = $original['parent_section'];
            }
            $answered[$index] = $entry;
        }
        return $answered;
    }

    /**
     * The activities of the template, moved to the sections the resize left them in.
     *
     * An activity follows its section to the new number; the activities of a
     * dropped section go with it. A clone of a unit also holds what the AI is
     * to write in the unit it copies (the "instance" activities, which belong
     * to no real activity), each with a uid and a cmid of its own. What the
     * template keeps as it is stays only in its own section: a kept activity is
     * copied from one place, once.
     *
     * @param array $activities The payload's activities, as built from the template.
     * @param array $sections The resized sections_info, from resize().
     * @param int|null $templatesections How many sections the template has, when known: only then can the
     *        activities be left untouched, since a section that was removed must take its activities along.
     * @return array
     */
    public static function activities(array $activities, array $sections, ?int $templatesections = null): array {
        $map = template_section_map::from_sections_info($sections);
        if ($templatesections !== null && $map->is_identity($templatesections)) {
            return $activities;
        }

        $moved = [];
        foreach ($activities as $activity) {
            $target = $map->target_of_source((int) ($activity['parameters']['section'] ?? 0));
            if ($target === null) {
                continue;
            }
            $activity['parameters']['section'] = $target;
            $moved[] = $activity;
        }

        $nextcmid = min(array_merge([0], array_column($activities, 'cmid'))) - 1;
        foreach ($sections as $section) {
            if (empty($section['cloned'])) {
                continue;
            }
            foreach ($activities as $activity) {
                $action = $activity['template_behavior']['action'] ?? '';
                $source = (int) ($activity['parameters']['section'] ?? 0);
                if ($action !== 'instance' || $source !== (int) $section['source_section']) {
                    continue;
                }
                $activity['uid'] = template_export_uids::random_uid();
                $activity['cmid'] = $nextcmid--;
                $activity['parameters']['section'] = (int) $section['section'];
                $moved[] = $activity;
            }
        }
        return $moved;
    }

    /**
     * The order of the result: [source number, cloned] for each section.
     *
     * @param template_section_tree $tree
     * @param array $entries
     * @param int[] $current The units of the template.
     * @param int $units The wanted number of units.
     * @return array
     */
    private static function plan(template_section_tree $tree, array $entries, array $current, int $units): array {
        $dropped = [];
        $clones = [];
        if ($units < count($current)) {
            foreach (array_slice($current, $units) as $unit) {
                foreach ($tree->subtree_of($unit) as $number) {
                    $dropped[$number] = true;
                }
            }
        } else {
            $clones = array_fill(0, $units - count($current), end($current));
        }

        $lastunit = end($current);
        $lastend = max($tree->subtree_of($lastunit));
        $planned = [];
        foreach ($entries as $entry) {
            $number = (int) $entry['section'];
            if (isset($dropped[$number])) {
                continue;
            }
            $planned[] = [$number, false];
            if ($number === $lastend) {
                foreach ($clones as $model) {
                    foreach ($tree->subtree_of($model) as $copied) {
                        $planned[] = [$copied, true];
                    }
                }
            }
        }
        return $planned;
    }

    /**
     * Number the planned sections 0, 1, 2... and point every parent at its new number.
     *
     * @param template_section_tree $tree
     * @param array $entries
     * @param array $planned From plan().
     * @return array
     */
    private static function renumber(template_section_tree $tree, array $entries, array $planned): array {
        $bynumber = [];
        foreach ($entries as $entry) {
            $bynumber[(int) $entry['section']] = $entry;
        }

        $result = [];
        $latest = [];
        foreach ($planned as $newnumber => [$source, $cloned]) {
            $entry = $bynumber[$source];
            $parent = $tree->parent_of($source);
            // The parent of a section is the closest section before it that is its parent's counterpart: for a
            // clone that is the clone of that parent, for the rest the section itself.
            $newparent = ($parent > 0) ? $latest[$parent] : 0;
            $latest[$source] = $newnumber;

            $entry['section'] = $newnumber;
            $entry['parent_section'] = $newparent;
            if (isset($entry['format_options']) && is_array($entry['format_options'])) {
                $entry['format_options']['parent'] = $newparent;
            }
            $entry['source_section'] = $source;
            $entry['cloned'] = $cloned;
            $entry['depth'] = ($newparent > 0) ? $result[$newparent]['depth'] + 1 : 0;
            if ($cloned && isset($entry['uid'])) {
                $entry['uid'] = template_export_uids::random_uid();
            }
            $result[$newnumber] = $entry;
        }
        return array_values($result);
    }
}
