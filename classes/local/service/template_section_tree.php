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
 * The tree a format_flexsections course is made of.
 *
 * A flexsections section keeps the number of its parent as an option of its
 * own (0 for a top section); a parent always comes before its children, so the
 * numbering is the depth-first order of the tree. This reads that option from
 * the sections of a payload and answers the questions the rest of the plugin
 * asks of the tree, without reading the site.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_section_tree {
    /** @var int[] Section number => number of its parent, general section left out. */
    private array $parents = [];

    /** @var int[][] Parent number => numbers of its children, in order. */
    private array $children = [];

    /**
     * Use from_entries().
     *
     * @param int[] $parents Section number => number of its parent.
     */
    private function __construct(array $parents) {
        foreach ($parents as $number => $parent) {
            $this->parents[$number] = $parent;
            $this->children[$parent][] = $number;
        }
    }

    /**
     * The parent a section of the payload names.
     *
     * The explicit parent_section wins; the parent format option is what a
     * payload built before it carries.
     *
     * @param array $entry An entry of sections_info.
     * @return int
     */
    public static function parent_of_entry(array $entry): int {
        if (isset($entry['parent_section'])) {
            return (int) $entry['parent_section'];
        }
        $options = $entry['format_options'] ?? [];
        return (int) ($options['parent'] ?? 0);
    }

    /**
     * Build the tree of the sections of a payload.
     *
     * A parent that does not exist, or does not come before its child, is
     * treated as no parent: such a section is a top one instead of a broken one.
     *
     * @param array $entries The payload's sections_info.
     * @return self
     */
    public static function from_entries(array $entries): self {
        $numbers = [];
        foreach ($entries as $entry) {
            $numbers[(int) $entry['section']] = true;
        }
        ksort($numbers);

        $parents = [];
        foreach ($entries as $entry) {
            $number = (int) $entry['section'];
            if ($number === 0) {
                continue;
            }
            $parent = self::parent_of_entry($entry);
            if ($parent > 0 && ($parent >= $number || !isset($numbers[$parent]))) {
                debugging('Section ' . $number . ' names parent ' . $parent .
                    ', which is missing or does not come before it; it is treated as a top section.', DEBUG_DEVELOPER);
            }
            if ($parent <= 0 || $parent >= $number || !isset($numbers[$parent])) {
                $parent = 0;
            }
            $parents[$number] = $parent;
        }
        ksort($parents);
        return new self($parents);
    }

    /**
     * The tree of a course, or null when its format has no subsections.
     *
     * @param \course_modinfo $modinfo
     * @return self|null
     */
    public static function from_modinfo(\course_modinfo $modinfo): ?self {
        $course = $modinfo->get_course();
        if ($course->format !== 'flexsections') {
            return null;
        }
        $format = course_get_format($course);
        $entries = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $entries[] = [
                'section' => (int) $section->section,
                'format_options' => $format->get_format_options($section),
            ];
        }
        return self::from_entries($entries);
    }

    /**
     * The parent of a section, 0 for a top one.
     *
     * @param int $number
     * @return int
     */
    public function parent_of(int $number): int {
        return $this->parents[$number] ?? 0;
    }

    /**
     * The children of a section, in order.
     *
     * @param int $number
     * @return int[]
     */
    public function children_of(int $number): array {
        return $this->children[$number] ?? [];
    }

    /**
     * How many levels a section is under the top: 0 for a top section.
     *
     * @param int $number
     * @return int
     */
    public function depth_of(int $number): int {
        $depth = 0;
        $parent = $this->parent_of($number);
        while ($parent > 0) {
            $depth++;
            $parent = $this->parent_of($parent);
        }
        return $depth;
    }

    /**
     * A section and all its descendants, in numbering order.
     *
     * @param int $number
     * @return int[]
     */
    public function subtree_of(int $number): array {
        $subtree = [$number];
        foreach ($this->children_of($number) as $child) {
            $subtree = array_merge($subtree, $this->subtree_of($child));
        }
        return $subtree;
    }

    /**
     * The units of the course: the top sections that have children.
     *
     * @return int[]
     */
    public function units(): array {
        $units = [];
        foreach ($this->children_of(0) as $number) {
            if ($this->children_of($number)) {
                $units[] = $number;
            }
        }
        return $units;
    }
}
