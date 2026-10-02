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

use local_coursegen\local\reference\reference_file_policy;

/**
 * Which files a place of a template accepts.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_policy
 */
final class reference_file_policy_test extends \advanced_testcase {
    /**
     * Files and the kinds of place that take them.
     *
     * @return array
     */
    public static function accepted_provider(): array {
        return [
            'pdf in a document place' => [reference_file_policy::KIND_DOCUMENT, 'guide.pdf', true],
            'word in a document place' => [reference_file_policy::KIND_DOCUMENT, 'guide.docx', true],
            'presentation in a document place' => [reference_file_policy::KIND_DOCUMENT, 'slides.pptx', true],
            'upper case extension' => [reference_file_policy::KIND_DOCUMENT, 'GUIDE.PDF', true],
            'image in a document place' => [reference_file_policy::KIND_DOCUMENT, 'cover.png', true],
            'png in an image place' => [reference_file_policy::KIND_IMAGE, 'cover.png', true],
            'jpg in an image place' => [reference_file_policy::KIND_IMAGE, 'cover.jpg', true],
            'pdf in an image place' => [reference_file_policy::KIND_IMAGE, 'guide.pdf', false],
            'mp4 in a video place' => [reference_file_policy::KIND_VIDEO, 'clip.mp4', true],
            'mp3 in a video place' => [reference_file_policy::KIND_VIDEO, 'clip.mp3', false],
            'mp3 in an audio place' => [reference_file_policy::KIND_AUDIO, 'clip.mp3', true],
            'mp4 in an audio place' => [reference_file_policy::KIND_AUDIO, 'clip.mp4', false],
            'html is never accepted' => [reference_file_policy::KIND_DOCUMENT, 'page.html', false],
            'script is never accepted' => [reference_file_policy::KIND_DOCUMENT, 'run.js', false],
            'svg is never accepted' => [reference_file_policy::KIND_IMAGE, 'cover.svg', false],
            'svg in a document place' => [reference_file_policy::KIND_DOCUMENT, 'cover.svg', false],
            'php is never accepted' => [reference_file_policy::KIND_DOCUMENT, 'run.php', false],
            'no extension' => [reference_file_policy::KIND_DOCUMENT, 'guide', false],
            'double extension ends in html' => [reference_file_policy::KIND_DOCUMENT, 'guide.pdf.html', false],
        ];
    }

    /**
     * A place accepts the files of its own kind and never a file the site would serve as a page.
     *
     * @dataProvider accepted_provider
     * @param string $kind
     * @param string $filename
     * @param bool $expected
     */
    public function test_a_place_accepts_the_files_of_its_kind(string $kind, string $filename, bool $expected): void {
        $accepted = reference_file_policy::accepts($kind, $filename);

        $this->assertSame($expected, $accepted);
    }

    /**
     * The extensions offered to the browser come with their dot and leave out the ones never accepted.
     */
    public function test_the_extensions_offered_leave_out_the_ones_never_accepted(): void {
        $extensions = reference_file_policy::extensions_for(reference_file_policy::KIND_DOCUMENT);

        $this->assertContains('.pdf', $extensions);
        $this->assertNotContains('.html', $extensions);
        $this->assertNotContains('.svg', $extensions);
        $this->assertNotContains('.js', $extensions);
    }

    /**
     * An image place offers images only.
     */
    public function test_an_image_place_offers_images_only(): void {
        $extensions = reference_file_policy::extensions_for(reference_file_policy::KIND_IMAGE);

        $this->assertContains('.png', $extensions);
        $this->assertNotContains('.pdf', $extensions);
    }

    /**
     * Types of the template's file and the kind of place they make.
     *
     * @return array
     */
    public static function kind_provider(): array {
        return [
            'image' => ['image/png', 'a.png', reference_file_policy::KIND_IMAGE],
            'video' => ['video/mp4', 'a.mp4', reference_file_policy::KIND_VIDEO],
            'audio' => ['audio/mpeg', 'a.mp3', reference_file_policy::KIND_AUDIO],
            'pdf' => ['application/pdf', 'a.pdf', reference_file_policy::KIND_DOCUMENT],
            'no type, image name' => ['', 'a.jpg', reference_file_policy::KIND_IMAGE],
            'no type, pdf name' => ['', 'a.pdf', reference_file_policy::KIND_DOCUMENT],
            'no type, unknown name' => ['', 'a.unknownext', reference_file_policy::KIND_DOCUMENT],
        ];
    }

    /**
     * The kind of place follows the type of the file it holds, or its name when the export gave no type.
     *
     * @dataProvider kind_provider
     * @param string $mimetype
     * @param string $filename
     * @param string $expected
     */
    public function test_the_kind_follows_the_type_of_the_template_file(string $mimetype, string $filename, string $expected): void {
        $kind = reference_file_policy::kind_of($mimetype, $filename);

        $this->assertSame($expected, $kind);
    }
}
