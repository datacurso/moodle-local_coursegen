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
 * The spaces of a template and the file the teacher brought for each one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_selection {
    /** @var file_space[] Every space of the template, in course order. */
    private array $spaces;

    /** @var \stored_file[] The teacher's file of each filled space, by the space's cmid. */
    private array $files;

    /**
     * Constructor.
     *
     * @param file_space[] $spaces
     * @param \stored_file[] $files The teacher's file of each filled space, by the space's cmid.
     */
    public function __construct(array $spaces, array $files) {
        $this->spaces = $spaces;
        $this->files = $files;
    }

    /**
     * Whether a file is the template's own file of some space.
     *
     * @param \stored_file $file
     * @return bool
     */
    public function owns(\stored_file $file): bool {
        return $this->space_holding($file) !== null;
    }

    /**
     * The file the teacher brought for the space that holds a template file.
     *
     * @param \stored_file $templatefile
     * @return \stored_file|null Null when no space holds the file or the teacher brought none.
     */
    public function teacher_file_for(\stored_file $templatefile): ?\stored_file {
        $space = $this->space_holding($templatefile);
        if ($space === null) {
            return null;
        }
        return $this->files[$space->cmid] ?? null;
    }

    /**
     * Whether a template file belongs to a space the teacher brought no file for.
     *
     * @param \stored_file $templatefile
     * @return bool
     */
    public function is_unfilled(\stored_file $templatefile): bool {
        $space = $this->space_holding($templatefile);
        if ($space === null) {
            return false;
        }
        return !isset($this->files[$space->cmid]);
    }

    /**
     * The spaces the teacher brought a file for.
     *
     * @return file_space[]
     */
    public function filled(): array {
        return array_values(array_filter($this->spaces, fn(file_space $space) => isset($this->files[$space->cmid])));
    }

    /**
     * The spaces the teacher brought no file for.
     *
     * @return file_space[]
     */
    public function unfilled(): array {
        return array_values(array_filter($this->spaces, fn(file_space $space) => !isset($this->files[$space->cmid])));
    }

    /**
     * The cmids of the spaces the teacher brought a file for.
     *
     * @return int[]
     */
    public function filled_cmids(): array {
        return array_map(static fn(file_space $space) => $space->cmid, $this->filled());
    }

    /**
     * The teacher's file of a space.
     *
     * @param file_space $space
     * @return \stored_file|null
     */
    public function file_of(file_space $space): ?\stored_file {
        return $this->files[$space->cmid] ?? null;
    }

    /**
     * The required spaces the teacher brought no file for.
     *
     * @return file_space[]
     */
    public function missing_required(): array {
        return array_values(array_filter($this->unfilled(), static fn(file_space $space) => $space->required));
    }

    /**
     * The space that holds a template file.
     *
     * @param \stored_file $file
     * @return file_space|null
     */
    private function space_holding(\stored_file $file): ?file_space {
        foreach ($this->spaces as $space) {
            if ($space->holds($file)) {
                return $space;
            }
        }
        return null;
    }
}
