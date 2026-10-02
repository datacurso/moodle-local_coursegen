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

namespace local_coursegen\local\files;

/**
 * A row of an activity that may hold texts which reference files.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_carrier {
    /**
     * Constructor.
     *
     * @param string $table
     * @param int $id
     * @param file_area[] $areas
     */
    public function __construct(
        /** @var string The table the row is in. */
        public readonly string $table,
        /** @var int The id of the row. */
        public readonly int $id,
        /** @var file_area[] The areas the row's files may be in: its own, or its nearest parent's that declares any. */
        public readonly array $areas
    ) {
    }
}
