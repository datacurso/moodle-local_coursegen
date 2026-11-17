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

use core_availability\tree;

/**
 * The access restrictions of a template item, rewritten for the course that is built from it.
 *
 * The restrictions are handled through Moodle's own availability tree, so every kind of condition (and every one
 * a plugin adds) is carried as Moodle carries it when a course is restored: the ids a condition holds are pointed
 * at the counterpart in the new course through the condition's own update_dependency_id. A reference that has no
 * counterpart takes the value Moodle's restore gives it (a module or grade item 0, a group or grouping -1), so the
 * restriction is shown as broken and nobody is let in by mistake. The conditions on a date are moved by the same
 * number of seconds the new course starts after (or before) the template's course, so the restriction opens at the
 * same point of the course; only the date of the condition changes, never its operator or what it shows. When there
 * is no usable shift (the new course has no start date to measure from) the conditions on a date are left out, as a
 * date of the template means nothing for a course of another period.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_availability_tree {
    /** @var int Added to an id while it changes, so a new id never meets an old one that is still waiting. */
    private const TEMPORARY = 1000000000;

    /** @var string[] The tables a condition may refer to, which the template's course owns. */
    private const TABLES = ['course_modules', 'grade_items', 'groups', 'groupings'];

    /**
     * The restrictions of a template item, ready for its counterpart in the new course.
     *
     * @param string|null $availability The restrictions as Moodle stores them (JSON), or empty for none.
     * @param array<string,array<int,int>> $maps For each table a condition may refer to: template id => new id.
     * @param array<string,array<int,bool>> $existing For each of those tables, the ids the new course owns.
     * @param int|null $delta Seconds to move every date condition by; 0 keeps the dates as they are and null
     *     leaves the date conditions out.
     * @return string|null The restrictions of the new item, or null when there is none.
     */
    public static function carry(?string $availability, array $maps, array $existing, ?int $delta = 0): ?string {
        $structure = json_decode((string) $availability);
        if (!is_object($structure)) {
            return null;
        }
        $structure = ($delta === null) ? self::without_dates($structure) : self::shift_dates($structure, $delta);
        if ($structure === null) {
            return null;
        }
        try {
            $tree = new tree($structure);
        } catch (\Throwable $error) {
            // A condition of a plugin this site no longer has: Moodle keeps it as it is, and so does this.
            return json_encode($structure);
        }
        foreach (self::TABLES as $table) {
            self::point_at_new_ids($tree, $table, $maps[$table] ?? []);
        }
        $saved = json_decode(json_encode($tree->save()));
        self::mark_broken($saved, $existing);
        return json_encode($saved);
    }

    /**
     * The ids of the groups and groupings a tree of conditions names.
     *
     * @param string|null $availability The restrictions as Moodle stores them (JSON).
     * @return array{group: int[], grouping: int[]}
     */
    public static function group_ids(?string $availability): array {
        $used = ['group' => [], 'grouping' => []];
        self::collect_group_ids(json_decode((string) $availability), $used);
        return ['group' => array_keys($used['group']), 'grouping' => array_keys($used['grouping'])];
    }

    /**
     * A set of conditions without the ones on a date, and without the sets that end up empty.
     *
     * @param \stdClass $set
     * @return \stdClass|null Null when nothing is left.
     */
    private static function without_dates(\stdClass $set): ?\stdClass {
        $children = [];
        $shown = [];
        foreach ($set->c ?? [] as $index => $child) {
            if (isset($child->c)) {
                $child = self::without_dates($child);
            } else if (($child->type ?? '') === 'date') {
                $child = null;
            }
            if ($child === null) {
                continue;
            }
            $children[] = $child;
            if (isset($set->showc[$index])) {
                $shown[] = $set->showc[$index];
            }
        }
        if ($children === []) {
            return null;
        }
        $set->c = $children;
        if (isset($set->showc)) {
            $set->showc = $shown;
        }
        return $set;
    }

    /**
     * A set of conditions with the date of every condition on a date moved, whatever the depth of the set.
     *
     * Pure: the set it is given is not changed.
     *
     * @param \stdClass $set
     * @param int $delta Seconds to add to each date (negative to move it earlier).
     * @return \stdClass A copy of the set; operators and what each condition shows are as they were.
     */
    public static function shift_dates(\stdClass $set, int $delta): \stdClass {
        $set = clone $set;
        if (isset($set->c) && is_array($set->c)) {
            $children = [];
            foreach ($set->c as $child) {
                if (!is_object($child)) {
                    $children[] = $child;
                    continue;
                }
                $child = clone $child;
                if (isset($child->c)) {
                    $child = self::shift_dates($child, $delta);
                } else if (($child->type ?? '') === 'date' && isset($child->t)) {
                    $child->t = (int) $child->t + $delta;
                }
                $children[] = $child;
            }
            $set->c = $children;
        }
        return $set;
    }

    /**
     * Change every id of a table in a tree to its counterpart, through a temporary value.
     *
     * @param tree $tree
     * @param string $table
     * @param array<int,int> $map Template id => new id.
     */
    private static function point_at_new_ids(tree $tree, string $table, array $map): void {
        foreach ($map as $old => $new) {
            $tree->update_dependency_id($table, (int) $old, -(self::TEMPORARY + (int) $new));
        }
        foreach ($map as $new) {
            $tree->update_dependency_id($table, -(self::TEMPORARY + (int) $new), (int) $new);
        }
    }

    /**
     * Give what still names something of the template's course the value Moodle's restore gives it.
     *
     * @param \stdClass $node A set of conditions, or one condition, read from the saved tree.
     * @param array<string,array<int,bool>> $existing
     */
    private static function mark_broken(\stdClass $node, array $existing): void {
        foreach ($node->c ?? [] as $child) {
            self::mark_broken($child, $existing);
        }
        $type = $node->type ?? '';
        if ($type === 'completion' && ($node->cm ?? 0) > 0 && !isset($existing['course_modules'][(int) $node->cm])) {
            $node->cm = 0;
        }
        if ($type === 'grade' && ($node->id ?? 0) > 0 && !isset($existing['grade_items'][(int) $node->id])) {
            $node->id = 0;
        }
        if ($type === 'group' && ($node->id ?? 0) > 0 && !isset($existing['groups'][(int) $node->id])) {
            $node->id = -1;
        }
        if ($type === 'grouping' && ($node->id ?? 0) > 0 && !isset($existing['groupings'][(int) $node->id])) {
            $node->id = -1;
        }
    }

    /**
     * Add to $used the ids of the groups and groupings a tree of conditions names.
     *
     * @param mixed $node
     * @param array $used
     */
    private static function collect_group_ids($node, array &$used): void {
        if (!is_object($node)) {
            return;
        }
        foreach ($node->c ?? [] as $child) {
            self::collect_group_ids($child, $used);
        }
        $type = $node->type ?? '';
        if (($type === 'group' || $type === 'grouping') && ($node->id ?? 0) > 0) {
            $used[$type][(int) $node->id] = true;
        }
    }
}
