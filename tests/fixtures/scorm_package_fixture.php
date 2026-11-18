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
 * Builds minimal SCORM package fixtures for the coursegen tests.
 *
 * A package written by our own AI service is always the same five entries -
 * imsmanifest.xml, index.html, scripts/quiz.js, scripts/pipwerks-scorm.js and
 * styles/style.css - with every piece of content injected as JSON inside
 * index.html. These fixtures reproduce that shape so the export assertions run
 * against text this file owns, byte for byte.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scorm_package_fixture {
    /**
     * @var string The raw index.html of a marker-bearing mold package.
     *
     * Written as a literal, never through json_encode(): the export ships this
     * text byte for byte and the tests compare it as such. The CONFIG block is
     * what tells the export the package came from our own generator, and the
     * markers inside it are what the service fills in.
     */
    public const MOLD_INDEX_HTML = '<!DOCTYPE html>' . "\n"
        . '<html lang="es">' . "\n"
        . '<head><meta charset="utf-8"><title>Cuestionario</title>' . "\n"
        . '<link rel="stylesheet" href="styles/style.css"></head>' . "\n"
        . '<body><div id="app"></div>' . "\n"
        . '<script>' . "\n"
        . 'const CONFIG = {"title":"[[coursegen: titulo del cuestionario]]",'
        . '"passingScore":70,"shuffle":true};' . "\n"
        . 'const QUESTIONS = [{"text":"[[coursegen:repeat: genera una pregunta por cada tema '
        . 'del silabo]]","options":["A","B"],"answer":0}];' . "\n"
        . '</script>' . "\n"
        . '<script src="scripts/pipwerks-scorm.js"></script>' . "\n"
        . '<script src="scripts/quiz.js"></script>' . "\n"
        . '</body></html>';

    /** @var string The repeat marker the mold's CONFIG block carries. */
    public const MOLD_MARKER = '[[coursegen:repeat: genera una pregunta por cada tema del silabo]]';

    /**
     * @var string The raw index.html of a package written by somebody else.
     *
     * Same file name, no CONFIG block: the service cannot rebuild it, so the
     * export has to say so instead of shipping its bytes.
     */
    public const THIRD_PARTY_INDEX_HTML = '<!DOCTYPE html>' . "\n"
        . '<html><head><title>Published with Storyline</title></head>' . "\n"
        . '<body><script>var globalProvideData = {"slides":[]};</script></body></html>';

    /** @var string The fixed manifest every fixture package carries. */
    private const IMSMANIFEST = '<?xml version="1.0" standalone="no" ?>' . "\n"
        . '<manifest identifier="coursegen-fixture" version="1.1"><organizations/><resources/></manifest>';

    /** @var string|null Cached bytes of the marker-bearing mold package. */
    private static ?string $moldbytes = null;

    /** @var string|null Cached bytes of a package that is not ours. */
    private static ?string $thirdpartybytes = null;

    /** @var string|null Cached bytes of a package without an index.html. */
    private static ?string $byteswithoutindex = null;

    /**
     * Bytes of a mold package written by our own generator.
     *
     * @return string
     */
    public static function mold_bytes(): string {
        if (self::$moldbytes === null) {
            self::$moldbytes = self::build_zip([
                'imsmanifest.xml' => self::IMSMANIFEST,
                'index.html' => self::MOLD_INDEX_HTML,
                'scripts/quiz.js' => '// quiz runtime',
                'scripts/pipwerks-scorm.js' => '// pipwerks wrapper',
                'styles/style.css' => 'body { margin: 0; }',
            ]);
        }
        return self::$moldbytes;
    }

    /**
     * Bytes of a readable package that our generator did not write.
     *
     * @return string
     */
    public static function third_party_bytes(): string {
        if (self::$thirdpartybytes === null) {
            self::$thirdpartybytes = self::build_zip([
                'imsmanifest.xml' => self::IMSMANIFEST,
                'index.html' => self::THIRD_PARTY_INDEX_HTML,
                'story_content/frame.js' => '// vendor runtime',
            ]);
        }
        return self::$thirdpartybytes;
    }

    /**
     * Bytes of a readable package whose entry point is not an index.html.
     *
     * @return string
     */
    public static function bytes_without_index(): string {
        if (self::$byteswithoutindex === null) {
            self::$byteswithoutindex = self::build_zip([
                'imsmanifest.xml' => self::IMSMANIFEST,
                'shared/launchpage.html' => '<html><body>Launch</body></html>',
            ]);
        }
        return self::$byteswithoutindex;
    }

    /**
     * Build a zip archive in a per-request temp directory and return its bytes.
     *
     * @param array $entries Map of archive path => file content.
     * @return string
     */
    private static function build_zip(array $entries): string {
        $temppath = make_request_directory() . '/scorm-fixture-' . count($entries) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($temppath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        return file_get_contents($temppath);
    }
}
