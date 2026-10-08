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

use local_coursegen\local\preview\preview_file_policy;

/**
 * Which files of the preview the browser may show inside a page.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\preview_file_policy
 */
final class preview_file_policy_test extends \basic_testcase {
    /**
     * The types a page can embed safely.
     *
     * @return array
     */
    public static function shown_inside_a_page(): array {
        return [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'upper case' => ['Application/PDF'],
            'with parameters' => ['application/pdf; charset=binary'],
        ];
    }

    /**
     * A PDF and a raster image are shown inside the page.
     *
     * @dataProvider shown_inside_a_page
     * @param string $mimetype
     */
    public function test_a_pdf_and_a_raster_image_are_shown_inside_the_page(string $mimetype): void {
        $this->assertTrue(preview_file_policy::is_shown_inline($mimetype, false));
    }

    /**
     * The types that can run a script in the site are always downloaded.
     *
     * @return array
     */
    public static function always_downloaded(): array {
        return [
            'html' => ['text/html'],
            'xhtml' => ['application/xhtml+xml'],
            'svg' => ['image/svg+xml'],
            'xml' => ['text/xml'],
            'javascript' => ['application/javascript'],
            'word' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'zip' => ['application/zip'],
            'plain text' => ['text/plain'],
            'unknown' => ['application/octet-stream'],
            'empty' => [''],
        ];
    }

    /**
     * A type that can run code, or that is unknown, is downloaded.
     *
     * @dataProvider always_downloaded
     * @param string $mimetype
     */
    public function test_a_type_that_can_run_code_or_that_is_unknown_is_downloaded(string $mimetype): void {
        $this->assertFalse(preview_file_policy::is_shown_inline($mimetype, false));
    }

    /**
     * An address that asks for the download wins over the type.
     */
    public function test_a_request_for_the_download_is_always_a_download(): void {
        $this->assertFalse(preview_file_policy::is_shown_inline('application/pdf', true));
        $this->assertFalse(preview_file_policy::is_shown_inline('image/png', true));
    }
}
