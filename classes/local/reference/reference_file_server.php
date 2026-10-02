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

namespace local_coursegen\local\reference;

use context;
use stored_file;

/**
 * Serves a file a teacher brought to the teacher who brought it, and to no one else.
 *
 * Only the files a generation uses are served: they are what the preview of
 * the generation shows. The staged ones are never addressed.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_server {
    /**
     * The stored file an address names, when the user may read it.
     *
     * @param context $context The context of the address.
     * @param string $filearea
     * @param array $args The itemid, then the path and the name.
     * @param int $userid The user asking.
     * @return stored_file|null
     */
    public static function file_for(context $context, string $filearea, array $args, int $userid): ?stored_file {
        if ($context->contextlevel !== CONTEXT_USER) {
            return null;
        }
        if ((int) $context->instanceid !== $userid) {
            return null;
        }
        if ($filearea !== reference_file_storage::SESSION_AREA) {
            return null;
        }
        if (count($args) < 3) {
            return null;
        }
        $itemid = (int) array_shift($args);
        $filename = array_pop($args);
        $filepath = '/' . implode('/', $args) . '/';
        $storage = get_file_storage();
        $file = $storage->get_file($context->id, reference_file_storage::COMPONENT, $filearea, $itemid, $filepath, $filename);
        if (!$file) {
            return null;
        }
        return $file;
    }
}
