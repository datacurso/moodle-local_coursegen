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
 * A file a new activity references that could not be given to it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_copy_exception extends \moodle_exception {
    /**
     * No source holds the file a text names, or this user may not copy it.
     *
     * @param string $where The activity and the field that reference it.
     * @param string $file The reference as written.
     * @return self
     */
    public static function missing(string $where, string $file): self {
        return new self('error_file_missing', 'local_coursegen', '', ['where' => $where, 'file' => $file]);
    }

    /**
     * The area the file belongs to cannot be told from the row that references it.
     *
     * @param string $where
     * @param string $file
     * @return self
     */
    public static function area_unknown(string $where, string $file): self {
        return new self('error_file_area_unknown', 'local_coursegen', '', ['where' => $where, 'file' => $file]);
    }

    /**
     * The area already holds a different file under the same name.
     *
     * @param string $where
     * @param string $file
     * @return self
     */
    public static function conflict(string $where, string $file): self {
        return new self('error_file_conflict', 'local_coursegen', '', ['where' => $where, 'file' => $file]);
    }
}
