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

namespace local_coursegen\local\link;

/**
 * Where one activity module stores the texts that may carry link tokens.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface module_link_texts {
    /**
     * The table holding the texts.
     *
     * @return string
     */
    public function table(): string;

    /**
     * The column of that table that holds the id of the module instance.
     *
     * @return string
     */
    public function instance_column(): string;

    /**
     * The columns of that table that may carry link tokens.
     *
     * @return string[]
     */
    public function text_columns(): array;

    /**
     * The file area a text column of a record keeps its files in, as the module serves it.
     *
     * @param string $column One of text_columns().
     * @param \stdClass $record A record of table().
     * @return array{0:string,1:string,2:int} Component, file area and item id.
     */
    public function file_area(string $column, \stdClass $record): array;
}
