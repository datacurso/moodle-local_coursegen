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

namespace local_coursegen\local\space;

/**
 * One file resource of a template that the teacher fills with a file.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_space {
    /**
     * Constructor.
     *
     * @param int $cmid The resource's course module id in the template's course.
     * @param string $name The resource's name.
     * @param string $instruction What the teacher is asked for; empty for nothing.
     * @param bool $required Whether the teacher must bring the file.
     * @param \stored_file[] $templatefiles The files of the resource in the template's course.
     */
    public function __construct(
        /** @var int The resource's course module id in the template's course. */
        public readonly int $cmid,
        /** @var string The resource's name. */
        public readonly string $name,
        /** @var string What the teacher is asked for; empty for nothing. */
        public readonly string $instruction,
        /** @var bool Whether the teacher must bring the file. */
        public readonly bool $required,
        /** @var \stored_file[] The files of the resource in the template's course. */
        public readonly array $templatefiles
    ) {
    }

    /**
     * Whether a file is one of the resource's own in the template's course.
     *
     * @param \stored_file $file
     * @return bool
     */
    public function holds(\stored_file $file): bool {
        foreach ($this->templatefiles as $own) {
            if ((int) $own->get_id() === (int) $file->get_id()) {
                return true;
            }
        }
        return false;
    }
}
