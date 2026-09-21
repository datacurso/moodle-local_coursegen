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

namespace local_coursegen\local\preview;

/**
 * Turns an activity's backup tree into the table => rows shape json_store
 * answers reads from. Kept apart from json_store itself: building the rows
 * is a one-time walk of the tree, answering reads is everything that happens
 * after, and the two do not share any state beyond the rows this produces.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_store_builder {
    /**
     * Turn the tree into rows, table by table.
     *
     * Every element with a known table is a row of it: its attributes and its
     * final values are the columns. The tree does not carry the column that
     * ties a row to its parent, because a backup restores those on the way
     * back in, so it is put back here from the element the row sits under.
     *
     * @param array $tree
     * @param array $tables Element name => table.
     * @param array $aliases Element name => declared column => table column.
     * @return array Table => list of rows, each a stdClass.
     */
    public static function build(array $tree, array $tables, array $aliases): array {
        $rows = [];
        // The tree opens with the "activity" wrapper, which is not a row of
        // anything; the module's own element hangs under it.
        foreach ($tree as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $node) {
                    if (is_array($node)) {
                        self::walk($rows, $name, $node, [], $tables, $aliases);
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * One element and everything under it.
     *
     * @param array $rows Accumulator, passed by reference: table => rows.
     * @param string $name The element's name.
     * @param array $node Its attributes, values and children.
     * @param array $ancestors Element name => id, for every row this one sits
     *                         under, outermost first.
     * @param array $tables
     * @param array $aliases
     */
    private static function walk(
        array &$rows,
        string $name,
        array $node,
        array $ancestors,
        array $tables,
        array $aliases
    ): void {
        $row = [];
        $children = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $children[$key] = $value;
                continue;
            }
            $column = $aliases[$name][$key] ?? $key;
            $row[$column] = $value;
        }

        $table = $tables[$name] ?? null;
        if ($table !== null) {
            // The keys of every row this one sits under, not only the nearest:
            // an answer belongs to its page and to its lesson, and mod_lesson
            // asks for it by both. Each under the two names Moodle tables use,
            // "lessonid" for a lesson's pages and "forum" for a forum's
            // discussions; a column the table does not have costs nothing in
            // a store that has no columns.
            foreach ($ancestors as $ancestorname => $ancestorid) {
                $row[$ancestorname . 'id'] ??= $ancestorid;
                $row[$ancestorname] ??= $ancestorid;
            }
            $rows[$table][] = (object) $row;
        }

        // A grouping element ("pages") is not a row; it holds the rows
        // ("page"). What it holds sits under the same rows it does.
        $below = $ancestors;
        if ($table !== null && isset($row['id'])) {
            $below[$name] = $row['id'];
        }

        foreach ($children as $childname => $items) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    self::walk($rows, $childname, $item, $below, $tables, $aliases);
                }
            }
        }
    }
}
