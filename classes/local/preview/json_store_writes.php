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
 * The few writes a json_store allows a caller outside a view page to make -
 * adding a row the payload does not carry, changing one field, taking rows
 * out. Kept apart from json_store.php only because together they crossed the
 * 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait json_store_writes {
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
}
