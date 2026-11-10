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

/**
 * Reads a file the person just picked, from their draft area.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\service;

/**
 * The file of a draft item of the current user.
 */
class draft_file {
    /**
     * The first file of a draft item of the current user.
     *
     * @param int $draftitemid Draft item id, 0 or less for none.
     * @return \stored_file|null Null when there is no such item or it holds no file.
     */
    public static function first(int $draftitemid): ?\stored_file {
        global $USER;

        if ($draftitemid <= 0) {
            return null;
        }
        $usercontext = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'itemid', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }
}
