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
 * A text whose file references all name their file with a placeholder.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rewritten_text {
    /**
     * Constructor.
     *
     * @param string $text
     * @param \stored_file[] $files Path after the placeholder, decoded ("/name.png") => the file it names.
     */
    public function __construct(
        /** @var string The text. */
        public readonly string $text,
        /** @var \stored_file[] The files the rewriting itself found, by the path the text now gives them. */
        public readonly array $files
    ) {
    }
}
