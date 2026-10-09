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
 * What writing the rewritten texts onto the copy of an activity did.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overlay_result {
    /** @var int How many texts were written. */
    private int $written = 0;

    /** @var string[] The paths of the texts that could not be written, readable. */
    private array $skipped = [];

    /**
     * Count one text written.
     *
     * @return void
     */
    public function written(): void {
        $this->written++;
    }

    /**
     * Remember one text that could not be written.
     *
     * @param tree_change $change The text left as the template has it.
     * @return void
     */
    public function skip(tree_change $change): void {
        $this->skipped[] = $change->describe();
    }

    /**
     * How many texts were written.
     *
     * @return int
     */
    public function count_written(): int {
        return $this->written;
    }

    /**
     * The paths of the texts that were left as the template has them.
     *
     * @return string[]
     */
    public function skipped_paths(): array {
        return $this->skipped;
    }
}
