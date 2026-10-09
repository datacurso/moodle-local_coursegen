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

namespace local_coursegen\local\structure;

/**
 * Finds the row of a real activity that a text of its tree belongs to.
 *
 * The tree of an activity is read from the structure its module declares for backup, so every list of rows sits
 * under the name of its element, and the tables and the renamed columns are known for each element.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class row_locator {
    /**
     * The row and the column that hold the text at a path of the tree.
     *
     * @param array $tree The tree of the activity whose rows are to be written.
     * @param array $path The keys and list positions that lead to the text.
     * @param array $tables Element name => table.
     * @param array $aliases Element name => name in the tree => column.
     * @return row_place|null Null when the tree has no such row or the element has no table.
     */
    public static function locate(array $tree, array $path, array $tables, array $aliases): ?row_place {
        $index = self::row_position($path);
        if ($index === null) {
            return null;
        }
        $element = (string) $path[$index - 1];
        if (!isset($tables[$element])) {
            return null;
        }
        $rowpath = array_slice($path, 0, $index + 1);
        $row = self::row_at($tree, $rowpath);
        if ($row === null || !isset($row['id'])) {
            return null;
        }
        $key = (string) end($path);
        $renamed = $aliases[$element] ?? [];
        $column = $renamed[$key] ?? $key;
        return new row_place((string) $tables[$element], (string) $column, (int) $row['id']);
    }

    /**
     * The position in the path of the list position that picks the row: the last whole number before the column.
     *
     * @param array $path The keys and list positions that lead to the text.
     * @return int|null Null when the path has no row, or the row has no element name before it.
     */
    private static function row_position(array $path): ?int {
        $last = count($path) - 2;
        for ($position = $last; $position >= 1; $position--) {
            if (is_int($path[$position])) {
                return $position;
            }
        }
        return null;
    }

    /**
     * The row that sits at a path of the tree.
     *
     * @param array $tree The tree of the activity.
     * @param array $rowpath The keys and list positions that lead to the row.
     * @return array|null Null when the path leads nowhere or not to a row.
     */
    private static function row_at(array $tree, array $rowpath): ?array {
        $row = $tree;
        foreach ($rowpath as $step) {
            if (!is_array($row) || !array_key_exists($step, $row)) {
                return null;
            }
            $row = $row[$step];
        }
        if (!is_array($row)) {
            return null;
        }
        return $row;
    }
}
