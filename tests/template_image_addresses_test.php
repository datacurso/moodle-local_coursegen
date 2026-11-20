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

use local_coursegen\local\files\generated_image_source;
use local_coursegen\local\service\template_image_addresses;
use local_coursegen\utils\generated_file_cache;

/**
 * Tests for template_image_addresses: the template picture a picture starts from, and the new one.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_image_addresses
 */
final class template_image_addresses_test extends \advanced_testcase {
    /** @var string A one pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var string The thread of the session. */
    private const THREAD = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /**
     * Store a picture.
     *
     * @param int $contextid
     * @param string $component
     * @param string $area
     * @param int $itemid
     * @param string $name
     * @return \stored_file
     */
    private function picture(int $contextid, string $component, string $area, int $itemid, string $name): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $name,
            'mimetype' => 'image/png',
        ], base64_decode(self::PNG));
    }

    /**
     * A template course with a cover slot, and a page whose content holds a picture.
     *
     * @return array [course, page module]
     */
    private function template(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '[[coursegen:reference: a cover]]']);
        $this->picture(\context_course::instance($course->id)->id, 'course', 'overviewfiles', 0, 'cover.png');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->picture(\context_module::instance($page->cmid)->id, 'mod_page', 'content', 0, 'Cápsula cabecera (12).png');
        return [$course, $page];
    }

    /**
     * A cache whose download stores the file it is asked for, counting the downloads.
     *
     * @param int $downloads Counts the downloads.
     * @return generated_file_cache
     */
    private function cache(int &$downloads): generated_file_cache {
        return new generated_file_cache(function (string $thread, string $fileid, string $filename, array $record) use (&$downloads) {
            $downloads++;
            return get_file_storage()->create_file_from_string($record, base64_decode(self::PNG));
        });
    }

    /**
     * The original of a slot picture is the template's picture of that slot.
     */
    public function test_the_original_of_a_slot_is_the_picture_of_its_place(): void {
        [$course] = $this->template();
        $addresses = new template_image_addresses((int) $course->id, self::THREAD);

        $url = $addresses->original(['kind' => 'course_cover', 'key' => 'course:cover']);

        $this->assertStringContainsString('/course/overviewfiles/cover.png', $url);
        $this->assertStringNotContainsString('/overviewfiles/0/', $url);
        $this->assertSame('', $addresses->original(['kind' => 'course_cover', 'key' => 'section:9:image']));
    }

    /**
     * The original of a reference picture is the template activity's file of that name.
     */
    public function test_the_original_of_a_reference_is_the_activity_file_of_its_name(): void {
        [$course, $page] = $this->template();
        $addresses = new template_image_addresses((int) $course->id, self::THREAD);

        $url = $addresses->original(['kind' => 'reference', 'reference' => 'Cápsula cabecera (12).png']);

        $contextid = \context_module::instance($page->cmid)->id;
        $this->assertStringContainsString("/$contextid/mod_page/content/0/", $url);
        $this->assertSame('', $addresses->original(['kind' => 'reference', 'reference' => 'missing.png']));
        $this->assertSame('', $addresses->original(['kind' => 'reference', 'reference' => '']));
    }

    /**
     * A picture made inside an activity starts from no template picture, and an unknown kind neither.
     */
    public function test_inline_and_unknown_pictures_have_no_original(): void {
        [$course] = $this->template();
        $addresses = new template_image_addresses((int) $course->id, self::THREAD);

        $this->assertSame('', $addresses->original(['kind' => 'inline', 'key' => 'inline:a:b']));
        $this->assertSame('', $addresses->original(['kind' => 'other', 'reference' => 'cover.png']));
    }

    /**
     * A stored file of the session's thread is downloaded once and served from the generated files.
     */
    public function test_a_stored_file_of_the_session_is_downloaded_once_and_served(): void {
        [$course] = $this->template();
        $downloads = 0;
        $addresses = new template_image_addresses((int) $course->id, self::THREAD, $this->cache($downloads));
        $item = ['thread_id' => self::THREAD, 'file_id' => str_repeat('a', 32) . '.png', 'filename' => 'cover-1.png'];

        $first = $addresses->current($item);
        $second = $addresses->current($item);

        $this->assertStringContainsString('/local_coursegen/' . generated_file_cache::FILEAREA . '/0/' . self::THREAD . '/cover-1.png', $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $downloads);
    }

    /**
     * A stored file of another thread is never downloaded nor served.
     */
    public function test_a_stored_file_of_another_thread_is_refused(): void {
        [$course] = $this->template();
        $downloads = 0;
        $addresses = new template_image_addresses((int) $course->id, self::THREAD, $this->cache($downloads));

        $other = $addresses->current(['thread_id' => 'another-thread', 'file_id' => str_repeat('b', 32) . '.png', 'filename' => 'x.png']);
        $none = $addresses->current(['file_id' => str_repeat('c', 32) . '.png', 'filename' => 'x.png']);

        $this->assertSame('', $other);
        $this->assertSame('', $none);
        $this->assertSame(0, $downloads);
    }

    /**
     * A picture saved inside an activity is downloaded by its path and served from the generated pictures.
     */
    public function test_a_saved_picture_is_downloaded_by_its_path(): void {
        [$course] = $this->template();
        $paths = [];
        $images = new generated_image_source(function (string $path, array $record) use (&$paths) {
            $paths[] = $path;
            return get_file_storage()->create_file_from_string($record, base64_decode(self::PNG));
        });
        $addresses = new template_image_addresses((int) $course->id, self::THREAD, null, $images);

        $url = $addresses->current(['path' => '/tmp/resource_files/generated_images/img_1.png']);

        $this->assertStringContainsString('/local_coursegen/' . generated_image_source::FILEAREA . '/0/', $url);
        $this->assertSame(['/tmp/resource_files/generated_images/img_1.png'], $paths);
    }

    /**
     * A path that is not one of the service's pictures, or nothing at all, gives no address and downloads nothing.
     *
     * @dataProvider refused_paths
     * @param string $path
     */
    public function test_a_path_that_is_not_a_service_picture_is_refused(string $path): void {
        [$course] = $this->template();
        $paths = [];
        $images = new generated_image_source(function (string $path) use (&$paths) {
            $paths[] = $path;
            return null;
        });
        $addresses = new template_image_addresses((int) $course->id, self::THREAD, null, $images);

        $this->assertSame('', $addresses->current(['path' => $path]));
        $this->assertSame([], $paths);
    }

    /**
     * Paths that must not be downloaded.
     *
     * @return array
     */
    public static function refused_paths(): array {
        return [
            'empty' => [''],
            'climbing' => ['/tmp/resource_files/generated_images/../../etc/passwd'],
            'web address' => ['https://example.org/a.png'],
            'site file' => ['@@PLUGINFILE@@/a.png'],
        ];
    }
}
