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

namespace local_coursegen\local\service\mold_export;

use cm_info;
use context_module;
use moodle_url;
use stdClass;

/**
 * A mod_folder mold: display settings plus every content file, with its HTML when it is a small text/html.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = ['display', 'showexpanded', 'showdownloadfolder', 'forcedownload'];

    /** @var int Largest HTML file whose content travels inline (bytes). */
    public const MAX_INLINE_HTML_BYTES = 200 * 1024;

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        return array_merge(
            static::common($cm, $record),
            static::instance_columns($record, self::SETTINGS),
            ['mod_settings' => ['files' => static::files($cm)]]
        );
    }

    /**
     * Every file of the folder's content area (directories skipped), sorted by path then name.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function files(cm_info $cm): array {
        $context = context_module::instance($cm->id);
        $stored = get_file_storage()->get_area_files($context->id, 'mod_folder', 'content', 0, 'filepath, filename', false);
        $files = [];
        foreach ($stored as $file) {
            $entry = [
                'file_name' => $file->get_filename(),
                'folder_path' => $file->get_filepath(),
                'mimetype' => (string) $file->get_mimetype(),
            ];
            if ($file->get_mimetype() === 'text/html' && $file->get_filesize() <= self::MAX_INLINE_HTML_BYTES) {
                $entry['content_html'] = $file->get_content();
            }
            $entry['pluginfile_url'] = moodle_url::make_pluginfile_url(
                $context->id,
                'mod_folder',
                'content',
                0,
                $file->get_filepath(),
                $file->get_filename()
            )->out(false);
            $files[] = $entry;
        }
        return $files;
    }
}
