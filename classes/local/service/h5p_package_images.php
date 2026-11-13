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

use cm_info;

/**
 * The images held by the .h5p package of an H5P activity.
 *
 * An H5P mold keeps its pictures inside its own package, under content/images/.
 * The AI service never receives those files, but it needs to know which ones
 * exist (and how big they are) so the content it writes can point at them: the
 * final package is rebuilt from the mold's own .h5p, which already holds them.
 * So the template export lists them, each one as the descriptor H5P writes into
 * content.json: its path relative to content/, its mime type and its size.
 *
 * The list is an optional addition to the export. A package that is missing,
 * unreadable or has no images gives an empty list and never an exception, so a
 * template whose mold cannot be read exports exactly as it did before.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_package_images {
    /** @var string The folder of a package that holds the content's own files. */
    private const IMAGES_FOLDER = 'content/images/';

    /** @var int Largest image, in bytes, that is read to learn its size. */
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    /**
     * The images of the package the H5P activity holds.
     *
     * @param cm_info $cm An h5pactivity course module.
     * @return array[] Each: path (relative to content/), mime, width and height.
     */
    public static function of_activity(cm_info $cm): array {
        try {
            $context = \context_module::instance($cm->id);
            $files = get_file_storage()->get_area_files(
                $context->id,
                'mod_h5pactivity',
                'package',
                0,
                'id',
                false
            );
            $file = reset($files);
            if (!$file) {
                return [];
            }

            $temppath = $file->copy_content_to_temp();
            try {
                return self::of_zip($temppath);
            } finally {
                @unlink($temppath);
            }
        } catch (\Throwable $e) {
            // The module, its stored package or the package itself is unusable: the template exports as if it had no images.
            debugging('local_coursegen: could not list the H5P package images: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }

    /**
     * The images inside a package on disk.
     *
     * @param string $path Full path of the .h5p file.
     * @return array[] Each: path (relative to content/), mime, width and height.
     */
    public static function of_zip(string $path): array {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $images = [];
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $image = self::image_at($zip, $index);
                if ($image !== null) {
                    $images[] = $image;
                }
            }
        } finally {
            $zip->close();
        }

        usort($images, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $images;
    }

    /**
     * One zip entry as an image descriptor, or null when it is not an image.
     *
     * @param \ZipArchive $zip
     * @param int $index
     * @return array|null
     */
    private static function image_at(\ZipArchive $zip, int $index): ?array {
        $stat = $zip->statIndex($index);
        if (!$stat) {
            return null;
        }
        $name = $stat['name'];
        if (
            strpos($name, self::IMAGES_FOLDER) !== 0
            || substr($name, -1) === '/'
            || strpos($name, '..') !== false
            || $stat['size'] <= 0
            || $stat['size'] > self::MAX_IMAGE_BYTES
        ) {
            return null;
        }

        $bytes = $zip->getFromIndex($index);
        if ($bytes === false) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return null;
        }

        return [
            'path' => substr($name, strlen('content/')),
            'mime' => $info['mime'],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }
}
