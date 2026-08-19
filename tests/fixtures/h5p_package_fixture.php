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

namespace local_coursegen;

/**
 * Builds minimal H5P package fixtures for the coursegen tests.
 *
 * The plugin validates downloaded packages at creation time (extension,
 * non-empty content, readable zip with an h5p.json manifest), so simulated
 * downloads must return structurally valid packages. No core_h5p deployment
 * is ever attempted on these fixtures.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class h5p_package_fixture {
    /** @var string|null Cached bytes of the valid fixture package. */
    private static ?string $bytes = null;

    /** @var string|null Cached bytes of the zip without an h5p.json manifest. */
    private static ?string $byteswithoutmanifest = null;

    /**
     * Bytes of a structurally valid .h5p package (zip with h5p.json).
     *
     * @return string
     */
    public static function bytes(): string {
        if (self::$bytes === null) {
            self::$bytes = self::build_zip([
                'h5p.json' => json_encode([
                    'title' => 'Fixture activity',
                    'mainLibrary' => 'H5P.Fixture',
                    'language' => 'en',
                    'embedTypes' => ['div'],
                    'preloadedDependencies' => [],
                ]),
                'content/content.json' => '{}',
            ]);
        }
        return self::$bytes;
    }

    /**
     * Bytes of a readable zip that is missing the h5p.json manifest.
     *
     * @return string
     */
    public static function bytes_without_manifest(): string {
        if (self::$byteswithoutmanifest === null) {
            self::$byteswithoutmanifest = self::build_zip([
                'content/content.json' => '{}',
            ]);
        }
        return self::$byteswithoutmanifest;
    }

    /**
     * Build a zip archive in a per-request temp directory and return its bytes.
     *
     * @param array $entries Map of archive path => file content.
     * @return string
     */
    private static function build_zip(array $entries): string {
        $temppath = make_request_directory() . '/fixture-' . count($entries) . '.h5p';

        $zip = new \ZipArchive();
        $zip->open($temppath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        return file_get_contents($temppath);
    }
}
