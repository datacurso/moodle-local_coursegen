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

use local_coursegen\local\service\h5p_package_images;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the listing of the images held by an H5P package.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\h5p_package_images
 */
final class h5p_package_images_test extends \advanced_testcase {
    /** @var string A valid 1x1 PNG, base64 encoded. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Write a zip with the given entries to a request directory.
     *
     * @param array<string, string> $entries Archive path => bytes.
     * @return string Full path of the zip.
     */
    private function zip_with(array $entries): string {
        $path = make_request_directory() . '/package.h5p';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE) === true);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        return $path;
    }

    /**
     * Every image under content/images/ is listed with the descriptor H5P writes, relative to content/.
     */
    public function test_it_lists_the_images_of_the_content_folder(): void {
        $png = base64_decode(self::PNG_1X1);
        $path = $this->zip_with([
            'h5p.json' => '{}',
            'content/content.json' => '{}',
            'content/images/b_second.png' => $png,
            'content/images/a_first.png' => $png,
        ]);

        $images = h5p_package_images::of_zip($path);

        $this->assertSame([
            ['path' => 'images/a_first.png', 'mime' => 'image/png', 'width' => 1, 'height' => 1],
            ['path' => 'images/b_second.png', 'mime' => 'image/png', 'width' => 1, 'height' => 1],
        ], $images);
    }

    /**
     * Anything that is not an image of the content folder is left out.
     */
    public function test_it_skips_what_is_not_an_image_of_the_content_folder(): void {
        $png = base64_decode(self::PNG_1X1);
        $path = $this->zip_with([
            'h5p.json' => '{}',
            'content/images/notes.txt' => 'not an image',
            'content/images/empty.png' => '',
            'content/audios/sound.png' => $png,
            'H5P.Image-1.1/images/icon.png' => $png,
            'images/outside.png' => $png,
            'content/images/ok.png' => $png,
        ]);

        $images = h5p_package_images::of_zip($path);

        $this->assertSame(['images/ok.png'], array_column($images, 'path'));
    }

    /**
     * A package with no images gives an empty list.
     */
    public function test_a_package_without_images_gives_an_empty_list(): void {
        $path = $this->zip_with(['h5p.json' => '{}', 'content/content.json' => '{}']);

        $this->assertSame([], h5p_package_images::of_zip($path));
    }

    /**
     * A file that is not a zip, or does not exist, never throws.
     */
    public function test_an_unreadable_package_gives_an_empty_list(): void {
        $notzip = make_request_directory() . '/broken.h5p';
        file_put_contents($notzip, 'this is not a zip');

        $this->assertSame([], h5p_package_images::of_zip($notzip));
        $this->assertSame([], h5p_package_images::of_zip($notzip . '.missing'));
    }

    /**
     * The package an H5P activity stores is the one listed.
     */
    public function test_it_lists_the_images_of_the_stored_package_of_an_activity(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'packagefilepath' => $CFG->dirroot . '/h5p/tests/fixtures/guess-the-answer.h5p',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);

        $images = h5p_package_images::of_activity($cm);

        $this->assertCount(1, $images);
        $this->assertSame('image/jpeg', $images[0]['mime']);
        $this->assertStringStartsWith('images/solutionImage-', $images[0]['path']);
        $this->assertGreaterThan(0, $images[0]['width']);
        $this->assertGreaterThan(0, $images[0]['height']);
    }

    /**
     * A lookup that fails anywhere (here: the module is gone, so its context is not found) gives an empty list.
     */
    public function test_a_failing_lookup_gives_an_empty_list_and_never_throws(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'packagefilepath' => $CFG->dirroot . '/h5p/tests/fixtures/guess-the-answer.h5p',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        course_delete_module($cm->id);

        $this->assertSame([], h5p_package_images::of_activity($cm));
        $this->assertDebuggingCalled();
    }
}
