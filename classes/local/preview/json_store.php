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

use stdClass;

/**
 * The rows of an activity, answered from the payload instead of the database.
 *
 * A module's own rendering code asks the database for its rows by table and
 * by column: the lesson, its pages, the answers of a page. The payload carries
 * every one of those rows already, as the tree the module's backup declares,
 * and the tree says which table each element was read from. So the same
 * questions can be answered from the payload, and rendering code that asks
 * them can run against an activity that does not exist.
 *
 * This is the whole of what makes that possible, and it is written once: it
 * knows nothing about any module. What it knows is how a backup tree is
 * shaped, which is the same for all of them.
 *
 * It answers the reads a view page makes. It has no writes, because a preview
 * changes nothing, and rendering code that tries to write during a view is
 * asking for something a preview cannot give.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_store {
    /** @var array Table => list of rows, each a stdClass. */
    protected array $rows = [];

    /**
     * Build the store from one activity of the payload.
     *
     * @param array $activity The activity as the payload describes it.
     * @return self
     */
    public static function from_activity(array $activity): self {
        $parameters = $activity['parameters'] ?? [];
        $store = new self();
        $store->load(
            (array) ($parameters['structure'] ?? []),
            (array) ($parameters['structure_tables'] ?? []),
            (array) ($parameters['structure_aliases'] ?? [])
        );
        return $store;
    }

    /**
     * Put one row in, for the few things a module reads that are not its own.
     *
     * A view page reads its course module, its course and its context as well
     * as the activity, and none of those are in the activity's own tree. They
     * are given to the store by whoever knows what they should be.
     *
     * @param string $table
     * @param array|stdClass $row
     */
    public function add(string $table, $row): void {
        $this->rows[$table][] = (object) $row;
    }

    /**
     * Take out the rows of a table that match, the way $DB->delete_records() does.
     *
     * @param string $table
     * @param array $conditions
     */
    public function delete_records(string $table, array $conditions = []): void {
        $this->rows[$table] = array_values(array_filter(
            $this->rows[$table] ?? [],
            fn(stdClass $row): bool => !$this->matches($row, $conditions)
        ));
    }

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
     */
    protected function load(array $tree, array $tables, array $aliases): void {
        // The tree opens with the "activity" wrapper, which is not a row of
        // anything; the module's own element hangs under it.
        foreach ($tree as $name => $value) {
            if (is_array($value)) {
                $this->load_element_nodes($name, $value, $tables, $aliases);
            }
        }
    }

    /**
     * Walk every node of one top-level element name.
     *
     * @param string $name
     * @param array $nodes
     * @param array $tables
     * @param array $aliases
     */
    private function load_element_nodes(string $name, array $nodes, array $tables, array $aliases): void {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->walk($name, $node, [], $tables, $aliases);
            }
        }
    }

    /**
     * One element and everything under it.
     *
     * @param string $name The element's name.
     * @param array $node Its attributes, values and children.
     * @param array $ancestors Element name => id, for every row this one sits
     *                         under, outermost first.
     * @param array $tables
     * @param array $aliases
     */
    protected function walk(string $name, array $node, array $ancestors, array $tables, array $aliases): void {
        [$row, $children] = $this->split_node($node, $name, $aliases);

        $table = $tables[$name] ?? null;
        if ($table !== null) {
            // The keys of every row this one sits under, not only the nearest:
            // an answer belongs to its page and to its lesson, and mod_lesson
            // asks for it by both. Each under the two names Moodle tables use,
            // "lessonid" for a lesson's pages and "forum" for a forum's
            // discussions; a column the table does not have costs nothing in
            // a store that has no columns.
            $row = $this->with_ancestor_columns($row, $ancestors);
            $this->rows[$table][] = (object) $row;
        }

        // A grouping element ("pages") is not a row; it holds the rows
        // ("page"). What it holds sits under the same rows it does.
        $below = $ancestors;
        if ($table !== null && isset($row['id'])) {
            $below[$name] = $row['id'];
        }

        $this->walk_children($children, $below, $tables, $aliases);
    }

    /**
     * Split one node into its own row columns and its child elements.
     *
     * @param array $node
     * @param string $name The element's name.
     * @param array $aliases
     * @return array [row, children]
     */
    private function split_node(array $node, string $name, array $aliases): array {
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
        return [$row, $children];
    }

    /**
     * Add every ancestor row's id, under both column names a module might ask for.
     *
     * @param array $row
     * @param array $ancestors Element name => id.
     * @return array
     */
    private function with_ancestor_columns(array $row, array $ancestors): array {
        foreach ($ancestors as $ancestorname => $ancestorid) {
            $row[$ancestorname . 'id'] ??= $ancestorid;
            $row[$ancestorname] ??= $ancestorid;
        }
        return $row;
    }

    /**
     * Walk every child element name's list of items.
     *
     * @param array $children Element name => list of item nodes.
     * @param array $below
     * @param array $tables
     * @param array $aliases
     */
    private function walk_children(array $children, array $below, array $tables, array $aliases): void {
        foreach ($children as $childname => $items) {
            $this->walk_child_items($childname, $items, $below, $tables, $aliases);
        }
    }

    /**
     * Walk every item of one child element name.
     *
     * @param string $childname
     * @param array $items
     * @param array $below
     * @param array $tables
     * @param array $aliases
     */
    private function walk_child_items(string $childname, array $items, array $below, array $tables, array $aliases): void {
        foreach ($items as $item) {
            if (is_array($item)) {
                $this->walk($childname, $item, $below, $tables, $aliases);
            }
        }
    }

    /**
     * Change one value of one row.
     *
     * A plan lays what it intends to write over the mould it will be written
     * into, page by page; this is how a drafted title or body replaces the
     * mould's on the row the module's code will read.
     *
     * @param string $table
     * @param mixed $id The row's id.
     * @param string $column
     * @param mixed $value
     * @return bool Whether a row with that id was there to change.
     */
    public function set(string $table, $id, string $column, $value): bool {
        foreach ($this->rows[$table] ?? [] as $row) {
            if ((string) ($row->id ?? '') === (string) $id) {
                $row->$column = $value;
                return true;
            }
        }
        return false;
    }

    /**
     * One row, or false.
     *
     * @param string $table
     * @param array $conditions Column => value, all of which must match.
     * @return stdClass|false
     */
    public function get_record(string $table, array $conditions = []) {
        foreach ($this->rows[$table] ?? [] as $row) {
            if ($this->matches($row, $conditions)) {
                return clone $row;
            }
        }
        return false;
    }

    /**
     * Every matching row, keyed by id the way the database answers.
     *
     * @param string $table
     * @param array $conditions
     * @param string $sort One column, optionally followed by ASC or DESC.
     * @return stdClass[]
     */
    public function get_records(string $table, array $conditions = [], string $sort = ''): array {
        $found = [];
        foreach ($this->rows[$table] ?? [] as $row) {
            if ($this->matches($row, $conditions)) {
                $found[] = clone $row;
            }
        }
        if ($sort !== '') {
            $found = $this->sorted($found, $sort);
        }
        $keyed = [];
        foreach ($found as $index => $row) {
            $keyed[$row->id ?? $index] = $row;
        }
        return $keyed;
    }

    /**
     * Whether any row matches.
     *
     * @param string $table
     * @param array $conditions
     * @return bool
     */
    public function record_exists(string $table, array $conditions = []): bool {
        return $this->get_record($table, $conditions) !== false;
    }

    /**
     * How many rows match.
     *
     * @param string $table
     * @param array $conditions
     * @return int
     */
    public function count_records(string $table, array $conditions = []): int {
        return count($this->get_records($table, $conditions));
    }

    /**
     * One column of the first matching row, or false.
     *
     * @param string $table
     * @param string $column
     * @param array $conditions
     * @return mixed
     */
    public function get_field(string $table, string $column, array $conditions = []) {
        $row = $this->get_record($table, $conditions);
        if ($row === false) {
            return false;
        }
        return $row->$column ?? null;
    }

    /**
     * Rows keyed by one column with another as the value.
     *
     * @param string $table
     * @param array $conditions
     * @param string $sort
     * @param string $keycolumn
     * @param string $valuecolumn
     * @return array
     */
    public function get_records_menu(
        string $table,
        array $conditions = [],
        string $sort = '',
        string $keycolumn = 'id',
        string $valuecolumn = ''
    ): array {
        $menu = [];
        foreach ($this->get_records($table, $conditions, $sort) as $row) {
            $columns = array_keys(get_object_vars($row));
            $value = $valuecolumn;
            if ($value === '') {
                $value = $columns[1] ?? $columns[0];
            }
            $menu[$row->$keycolumn] = $row->$value ?? null;
        }
        return $menu;
    }

    /**
     * Whether a row meets every condition.
     *
     * A condition compares as the database does: loosely, so "20" meets 20.
     *
     * @param stdClass $row
     * @param array $conditions
     * @return bool
     */
    protected function matches(stdClass $row, array $conditions): bool {
        foreach ($conditions as $column => $value) {
            if (!property_exists($row, $column)) {
                return false;
            }
            if ((string) $row->$column !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rows ordered by one column.
     *
     * @param stdClass[] $rows
     * @param string $sort "column", "column ASC" or "column DESC".
     * @return stdClass[]
     */
    protected function sorted(array $rows, string $sort): array {
        $parts = preg_split('~\s+~', trim($sort));
        $column = $parts[0];
        $descending = isset($parts[1]) && strtoupper($parts[1]) === 'DESC';
        usort($rows, static function (stdClass $a, stdClass $b) use ($column, $descending): int {
            $left = $a->$column ?? null;
            $right = $b->$column ?? null;
            $order = strcmp((string) $left, (string) $right);
            if (is_numeric($left) && is_numeric($right)) {
                $order = $left <=> $right;
            }
            if ($descending) {
                return -$order;
            }
            return $order;
        });
        return $rows;
    }
}
