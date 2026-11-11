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

use local_coursegen\utils\generated_file_cache;

/**
 * Serves a generated file stored for a review preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_file_server {
    /**
     * Send the stored file an address of the preview names, or report there is none.
     *
     * @param \context $context The context of the address.
     * @param array $args Path after the item id: the thread, then the file name.
     * @param bool $forcedownload
     * @param array $options
     * @return bool False when the file cannot be served; otherwise the file is sent and the script ends.
     */
    public static function serve(\context $context, array $args, bool $forcedownload, array $options): bool {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return false;
        }
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        array_shift($args);
        $filename = array_pop($args);
        $thread = array_shift($args);
        if (!$thread || !$filename || $args) {
            return false;
        }
        $fs = get_file_storage();
        $stored = $fs->get_file(
            $context->id,
            'local_coursegen',
            generated_file_cache::FILEAREA,
            0,
            '/' . $thread . '/',
            $filename
        );
        if (!$stored) {
            return false;
        }
        send_stored_file($stored, 0, 0, $forcedownload, $options);
        return true;
    }
}
