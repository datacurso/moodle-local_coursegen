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
    /**
     * @var string The raw h5p.json of a marker-bearing mold package.
     *
     * Written as a literal, never through json_encode(): the export ships this
     * text byte for byte and the tests compare it as such.
     */
    public const MOLD_H5P_JSON = '{"title":"Mapa del juego ⟦coursegen:titulo del mapa⟧",'
        . '"mainLibrary":"H5P.Fixture","language":"es","embedTypes":["div"],'
        . '"preloadedDependencies":[{"machineName":"H5P.Image","majorVersion":"1","minorVersion":"1"},'
        . '{"machineName":"H5P.Fixture","majorVersion":"1","minorVersion":"5"}]}';

    /**
     * @var string The raw content/content.json of a marker-bearing mold package.
     *
     * Shaped like a Game Map mold: the stage labels are the marked strings and
     * their telemetry pins them onto the drawn background.
     */
    public const MOLD_CONTENT_JSON = '{"gamemapSteps":{"backgroundImageSettings":'
        . '{"backgroundImage":{"path":"images/background.png","width":1280,"height":720}},'
        . '"gamemap":{"elements":[{"id":"1","label":"⟦coursegen:tema 1 del silabo⟧",'
        . '"telemetry":{"x":"12.5","y":"40.25"}},{"id":"2","label":"⟦coursegen:tema 2 del silabo⟧",'
        . '"telemetry":{"x":"48","y":"61.75"}}]}}}';

    /** @var string A binary-ish entry standing in for the mold's drawn background. */
    public const MOLD_BACKGROUND = "\x89PNG\r\n\x1a\n background bytes \x00\xff";

    /** @var string|null Cached bytes of the valid fixture package. */
    private static ?string $bytes = null;

    /** @var string|null Cached bytes of the zip without an h5p.json manifest. */
    private static ?string $byteswithoutmanifest = null;

    /** @var string|null Cached bytes of the marker-bearing mold package. */
    private static ?string $moldbytes = null;

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
     * Bytes of a mold package whose two text entries carry markers.
     *
     * A mold is the package the export reads its text out of, so this one owns
     * a real main library with its versions, marked strings in both text
     * entries and a non-text entry the rebuild has to preserve untouched.
     *
     * @return string
     */
    public static function mold_bytes(): string {
        if (self::$moldbytes === null) {
            self::$moldbytes = self::build_zip([
                'h5p.json' => self::MOLD_H5P_JSON,
                'content/content.json' => self::MOLD_CONTENT_JSON,
                'content/images/background.png' => self::MOLD_BACKGROUND,
                'H5P.Fixture-1.5/library.json' => '{"machineName":"H5P.Fixture"}',
            ]);
        }
        return self::$moldbytes;
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
