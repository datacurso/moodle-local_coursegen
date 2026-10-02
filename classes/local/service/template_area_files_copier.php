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


namespace local_coursegen\local\service;

/**
 * Copies the files of one file area from one item to another, inside the
 * course contexts of the template's course and of the new course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_area_files_copier {
    /**
     * Copy every file of an area, keeping its path and name.
     *
     * @param int $fromcontextid Context the files are in.
     * @param int $tocontextid Context the copies go to.
     * @param string $component
     * @param string $filearea
     * @param int $fromitemid Item the files belong to.
     * @param int $toitemid Item the copies belong to.
     */
    public static function copy(
        int $fromcontextid,
        int $tocontextid,
        string $component,
        string $filearea,
        int $fromitemid,
        int $toitemid
    ): void {
        $fs = get_file_storage();
        $files = $fs->get_area_files($fromcontextid, $component, $filearea, $fromitemid, 'id', false);
        foreach ($files as $file) {
            $fs->create_file_from_storedfile([
                'contextid' => $tocontextid,
                'itemid' => $toitemid,
            ], $file);
        }
    }
}
