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
 * One text of the tree of an activity that the template agent rewrote.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tree_change {
    /**
     * Constructor.
     *
     * @param array $path The keys and list positions that lead to the text, for example ['chapter', 2, 'content'].
     * @param string $value The text the agent wrote.
     */
    public function __construct(
        /** @var array The keys and list positions that lead to the text. */
        public readonly array $path,
        /** @var string The text the agent wrote. */
        public readonly string $value
    ) {
    }

    /**
     * The path as one readable line, for logs.
     *
     * @return string
     */
    public function describe(): string {
        return implode('/', $this->path);
    }
}
