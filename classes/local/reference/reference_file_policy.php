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

/**
 * Which files a place of a template accepts, by the kind of element that holds the file.
 *
 * What the site serves is decided by the extension of the file, so the
 * extension is what is checked. A file the site would serve as a page or run
 * as a script is never accepted, whatever the place.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_policy {
    /** @var string The element holds an image. */
    public const KIND_IMAGE = 'image';

    /** @var string The element holds a video. */
    public const KIND_VIDEO = 'video';

    /** @var string The element holds an audio file. */
    public const KIND_AUDIO = 'audio';

    /** @var string The element holds any other file: a document, a presentation, a spreadsheet. */
    public const KIND_DOCUMENT = 'document';

    /** @var string[] The type groups of the files that are served as a page or run as a script. */
    private const REFUSED_GROUPS = ['web_file'];

    /** @var string[] The extensions refused although their group is not, each with its dot. */
    private const REFUSED_EXTENSIONS = ['.svg', '.svgz', '.php', '.phtml', '.xml'];

    /** @var array<string,string[]> Kind of place => the type groups it accepts. */
    private const KIND_GROUPS = [
        self::KIND_IMAGE => ['web_image'],
        self::KIND_VIDEO => ['web_video'],
        self::KIND_AUDIO => ['web_audio'],
        self::KIND_DOCUMENT => ['document', 'presentation', 'spreadsheet', 'web_image', 'web_video', 'web_audio'],
    ];

    /**
     * The kind of place that holds a file of the template, from the type of that file.
     *
     * @param string $mimetype The type the export gives the file, empty when it lists none.
     * @param string $filename The name of the file, used when the export gave no type.
     * @return string One of the KIND_* constants.
     */
    public static function kind_of(string $mimetype, string $filename): string {
        $type = $mimetype;
        if ($type === '') {
            $type = mimeinfo('type', $filename);
        }
        if (str_starts_with($type, 'image/')) {
            return self::KIND_IMAGE;
        }
        if (str_starts_with($type, 'video/')) {
            return self::KIND_VIDEO;
        }
        if (str_starts_with($type, 'audio/')) {
            return self::KIND_AUDIO;
        }
        return self::KIND_DOCUMENT;
    }

    /**
     * Whether a place of this kind accepts a file with this name.
     *
     * @param string $kind One of the self::KIND_* constants.
     * @param string $filename
     * @return bool
     */
    public static function accepts(string $kind, string $filename): bool {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($extension === '') {
            return false;
        }
        $dotted = '.' . strtolower($extension);
        if (in_array($dotted, self::REFUSED_EXTENSIONS, true)) {
            return false;
        }
        if (file_extension_in_typegroup($filename, self::REFUSED_GROUPS)) {
            return false;
        }
        $groups = self::KIND_GROUPS[$kind];
        return file_extension_in_typegroup($filename, $groups);
    }

    /**
     * The extensions a place of this kind accepts, each with its dot.
     *
     * @param string $kind One of the self::KIND_* constants.
     * @return string[]
     */
    public static function extensions_for(string $kind): array {
        $groups = self::KIND_GROUPS[$kind];
        $extensions = file_get_typegroup('extension', $groups);
        $refused = file_get_typegroup('extension', self::REFUSED_GROUPS);
        $allowed = array_diff($extensions, $refused, self::REFUSED_EXTENSIONS);
        return array_values($allowed);
    }
}
