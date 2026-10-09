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
 * One file area a row of an activity keeps its files in.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_area {
    /**
     * Constructor.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     */
    public function __construct(
        /** @var int The context the files are stored in. */
        public readonly int $contextid,
        /** @var string The component that owns the area. */
        public readonly string $component,
        /** @var string The name of the area. */
        public readonly string $filearea,
        /** @var int The item id the files are stored under. */
        public readonly int $itemid
    ) {
    }
}
