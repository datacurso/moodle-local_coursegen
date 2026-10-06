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

use stored_file;

/**
 * The file a user left in one of their draft areas.
 *
 * A teacher picks a file with the file picker, which stores it in a draft area of the user. The plugin reads it
 * from there, sends it to the service and empties the area, so no copy of the file stays in the site files.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_draft_file {
    /**
     * The first file of a draft area of the current user.
     *
     * @param int $draftitemid Draft item id, for example 8421.
     * @return stored_file|null The file, or null when the area holds none.
     */
    public static function first(int $draftitemid): ?stored_file {
        global $USER;
        $context = \context_user::instance($USER->id);
        $storage = get_file_storage();
        $files = $storage->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }

    /**
     * Remove every file of a draft area of the current user.
     *
     * @param int $draftitemid Draft item id, for example 8421.
     */
    public static function remove_all(int $draftitemid): void {
        global $USER;
        $context = \context_user::instance($USER->id);
        $storage = get_file_storage();
        $storage->delete_area_files($context->id, 'user', 'draft', $draftitemid);
    }
}
