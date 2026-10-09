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
 * Writes the texts the agent rewrote onto the rows of the copy of the template activity.
 *
 * Only text columns are written. A text that has no row in the copy, or whose column is not a text, is left as the
 * template has it, and a row that the database refuses leaves the others untouched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overlay_writer {
    /** @var string[] The types Moodle gives to columns that hold text: char and text. */
    private const TEXT_TYPES = ['C', 'X'];

    /** @var array Table => its columns, read once. */
    private array $columns = [];

    /**
     * Write every rewritten text onto the row of the copy that matches it.
     *
     * @param tree_change[] $changes The texts the agent rewrote.
     * @param array $copytree The tree of the copy, read from its own rows.
     * @param array $tables Element name => table.
     * @param array $aliases Element name => name in the tree => column.
     * @return overlay_result
     */
    public function apply(array $changes, array $copytree, array $tables, array $aliases): overlay_result {
        $result = new overlay_result();
        foreach ($changes as $change) {
            $place = row_locator::locate($copytree, $change->path, $tables, $aliases);
            $this->write_or_skip($change, $place, $result);
        }
        return $result;
    }

    /**
     * Write one text when its row and column are fit for it, or remember that it was left alone.
     *
     * @param tree_change $change The rewritten text.
     * @param row_place|null $place Where it goes, null when the copy has no such row.
     * @param overlay_result $result Receives the outcome.
     * @return void
     */
    private function write_or_skip(tree_change $change, ?row_place $place, overlay_result $result): void {
        if ($place === null || !$this->is_text_column($place->table, $place->column)) {
            $result->skip($change);
            return;
        }
        $saved = $this->write($place, $change->value);
        if (!$saved) {
            $result->skip($change);
            return;
        }
        $result->written();
    }

    /**
     * Whether a column exists and holds text.
     *
     * @param string $table The table, without the prefix.
     * @param string $column The column.
     * @return bool
     */
    private function is_text_column(string $table, string $column): bool {
        global $DB;

        if (!array_key_exists($table, $this->columns)) {
            $this->columns[$table] = $DB->get_columns($table);
        }
        $columns = $this->columns[$table];
        if (!isset($columns[$column])) {
            return false;
        }
        $type = $columns[$column]->meta_type;
        return in_array($type, self::TEXT_TYPES, true);
    }

    /**
     * Save one text in its row.
     *
     * @param row_place $place The row and the column.
     * @param string $value The text.
     * @return bool False when the database refused it, for example a text longer than its column.
     */
    private function write(row_place $place, string $value): bool {
        global $DB;

        try {
            $DB->set_field($place->table, $place->column, $value, ['id' => $place->id]);
        } catch (\dml_exception $exception) {
            return false;
        }
        return true;
    }
}
