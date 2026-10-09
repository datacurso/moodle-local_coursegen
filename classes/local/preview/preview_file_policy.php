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

namespace local_coursegen\local\preview;

/**
 * Which files of the preview the browser may show inside a page.
 *
 * Only a PDF and a raster image are shown where a page embeds them. Every other type, and above all those that
 * can run a script in the site (HTML, SVG, XML), is downloaded: the files of a run are written by an AI.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_file_policy {
    /** @var string[] The types the browser shows inside a page. */
    private const INLINE_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /**
     * Whether the browser shows the file inside the page instead of downloading it.
     *
     * @param string $mimetype Type of the file, for example "application/pdf".
     * @param bool $forcedownload Whether the address asked for the download.
     * @return bool
     */
    public static function is_shown_inline(string $mimetype, bool $forcedownload): bool {
        if ($forcedownload) {
            return false;
        }
        $parts = explode(';', $mimetype);
        $base = strtolower(trim($parts[0]));
        return in_array($base, self::INLINE_TYPES, true);
    }
}
