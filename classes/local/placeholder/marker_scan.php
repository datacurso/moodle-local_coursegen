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

namespace local_coursegen\local\placeholder;

use local_coursegen\local\placeholder\marker_scanner;

/**
 * What the scan of one text found: the placeholders it carries and what is wrong with its markers.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class marker_scan {
    /**
     * Keep the findings of one scan.
     *
     * @param int $placeholders Slots, repeat blocks and references that are well formed, e.g. 3.
     * @param string[] $problems Short descriptions of malformed markers, e.g. ["a marker without an instruction"].
     * @param int $hidden Well formed markers inside a comment, a script or a style, e.g. 1.
     */
    public function __construct(
        /** @var int Slots, repeat blocks and references that are well formed. */
        public readonly int $placeholders,
        /** @var string[] Short descriptions of malformed markers. */
        public readonly array $problems,
        /** @var int Well formed markers inside a comment, a script or a style. */
        public readonly int $hidden
    ) {
    }

    /**
     * Whether the text carries at least one well formed placeholder.
     *
     * @return bool
     */
    public function has_placeholders(): bool {
        return $this->placeholders > 0;
    }
}
