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

use local_coursegen\local\service\template_course_image_applier;
use local_coursegen\utils\generated_file_cache;

/**
 * A picture the service made that cannot be read back does not stop the course from being made.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_course_image_applier
 */
final class template_course_image_applier_test extends \advanced_testcase {
    /**
     * The download fails: the course is left without the picture and nothing is thrown.
     */
    public function test_a_picture_that_cannot_be_downloaded_is_skipped(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cache = new generated_file_cache(static fn() => null);
        $entry = ['thread_id' => 'thread', 'file_id' => 'file.jpg', 'filename' => 'cover.jpg', 'size' => 10];
        $slots = [['key' => 'course:cover', 'kind' => 'course_cover', 'section' => null, 'filename' => 'cover.png']];

        template_course_image_applier::apply((int) $course->id, ['course:cover' => $entry], $slots, $cache);

        $this->assertDebuggingCalled();
        $this->assertTrue(true);
    }

    /**
     * A whole picture is kept even when the byte count the result states is not the one that arrived.
     */
    public function test_a_whole_image_is_accepted_when_its_size_differs(): void {
        $this->resetAfterTest();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $entry = ['thread_id' => 'thread', 'file_id' => 'file.png', 'filename' => 'cover.png', 'size' => strlen($png) + 7];
        $cache = new generated_file_cache(static function ($thread, $id, $name, $record) use ($png) {
            return get_file_storage()->create_file_from_string($record, $png);
        });

        $stored = $cache->get($entry);

        $this->assertSame(strlen($png), (int) $stored->get_filesize());
        $this->assertDebuggingCalled();
    }

    /**
     * Something that is not a whole image is not kept: it is downloaded again and then refused.
     */
    public function test_a_file_that_is_not_a_whole_image_is_refused(): void {
        $this->resetAfterTest();
        $entry = ['thread_id' => 'thread', 'file_id' => 'file.png', 'filename' => 'cover.png', 'size' => 1000];
        $calls = 0;
        $cache = new generated_file_cache(static function ($thread, $id, $name, $record) use (&$calls) {
            $calls++;
            return get_file_storage()->create_file_from_string($record, 'not an image');
        });

        try {
            $cache->get($entry);
            $this->fail('A file that is not an image must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_reference_download_failed', $exception->errorcode);
        }
        $this->assertSame(3, $calls);
    }
}
