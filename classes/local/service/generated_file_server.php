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

use local_coursegen\local\files\generated_image_source;
use local_coursegen\utils\generated_file_cache;

/**
 * Serves a file the AI service made for a template run: a stored file of the review preview, or a picture saved
 * inside an activity, shown in the list of the pictures of the run.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_file_server {
    /** @var string[] The areas served: the files of the review preview and the pictures saved inside activities. */
    private const AREAS = [generated_file_cache::FILEAREA, generated_image_source::FILEAREA];

    /**
     * Send the stored file an address of the preview names, or report there is none.
     *
     * @param \context $context The context of the address.
     * @param array $args Path after the item id: the thread, then the file name.
     * @param bool $forcedownload
     * @param array $options
     * @param string $filearea The area of the file: the generated files or the generated pictures.
     * @return bool False when the file cannot be served; otherwise the file is sent and the script ends.
     */
    public static function serve(
        \context $context,
        array $args,
        bool $forcedownload,
        array $options,
        string $filearea = generated_file_cache::FILEAREA
    ): bool {
        if (!in_array($filearea, self::AREAS, true)) {
            return false;
        }
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
            $filearea,
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
