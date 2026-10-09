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
 * Where a text lives in the rows of an activity: a table, a column and the id of the row.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class row_place {
    /**
     * Constructor.
     *
     * @param string $table The table of the row, without the prefix.
     * @param string $column The column that holds the text.
     * @param int $id The id of the row.
     */
    public function __construct(
        /** @var string The table of the row, without the prefix. */
        public readonly string $table,
        /** @var string The column that holds the text. */
        public readonly string $column,
        /** @var int The id of the row. */
        public readonly int $id
    ) {
    }
}
