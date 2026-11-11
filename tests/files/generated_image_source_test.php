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

namespace local_coursegen\local\files;

/**
 * The images the AI service made are fetched once and handed out like any other file.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\generated_image_source
 * @covers     \local_coursegen\local\files\file_sources
 */
final class generated_image_source_test extends \advanced_testcase {
    /**
     * A downloader that stores what it is asked for and counts its calls.
     *
     * @param array $calls Appended to with each path asked for.
     * @return callable
     */
    private function downloader(array &$calls): callable {
        return static function (string $path, array $record) use (&$calls) {
            $calls[] = $path;
            return get_file_storage()->create_file_from_string($record, 'IMAGE');
        };
    }

    /**
     * Paths the AI service holds images at, and paths that are not.
     *
     * @return array
     */
    public static function path_provider(): array {
        return [
            'generated images folder' => ['/srv/files/generated_images/a.png', true],
            'tmp' => ['/tmp/run-1/a.png', true],
            'data' => ['/data/a.png', true],
            'placeholder' => ['@@PLUGINFILE@@/a.png', false],
            'address' => ['https://example.org/a.png', false],
            'inline data' => ['data:image/png;base64,AAAA', false],
            'climbing' => ['/tmp/../etc/passwd', false],
            'odd characters' => ['/tmp/generated_images/ok;rm.png', false],
            'relative' => ['images/a.png', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Only the paths the AI service uses are images to fetch.
     *
     * @dataProvider path_provider
     * @param string $path
     * @param bool $expected
     */
    public function test_which_paths_are_images_of_the_service(string $path, bool $expected): void {
        $this->assertSame($expected, generated_image_source::is_image_path($path));
    }

    /**
     * An image is fetched once, stored in the plugin's area and found again from there.
     */
    public function test_an_image_is_fetched_once(): void {
        $this->resetAfterTest();
        $calls = [];
        $source = new generated_image_source($this->downloader($calls));
        $reference = new file_reference(file_reference::KIND_IMAGE_PATH, '/data/generated_images/chart.png');

        $first = $source->find($reference);
        $second = $source->find($reference);

        $this->assertSame('IMAGE', $first->get_content());
        $this->assertSame('chart.png', $first->get_filename());
        $this->assertSame(generated_image_source::FILEAREA, $first->get_filearea());
        $this->assertSame($first->get_id(), $second->get_id());
        $this->assertSame(['/data/generated_images/chart.png'], $calls);
    }

    /**
     * Two images with the same name in different folders are different files.
     */
    public function test_images_with_the_same_name_do_not_clash(): void {
        $this->resetAfterTest();
        $calls = [];
        $source = new generated_image_source($this->downloader($calls));

        $one = $source->find(new file_reference(file_reference::KIND_IMAGE_PATH, '/data/one/chart.png'));
        $two = $source->find(new file_reference(file_reference::KIND_IMAGE_PATH, '/data/two/chart.png'));

        $this->assertNotSame($one->get_id(), $two->get_id());
        $this->assertCount(2, $calls);
    }

    /**
     * An image without an extension gets one.
     */
    public function test_an_image_without_an_extension_gets_one(): void {
        $this->resetAfterTest();
        $calls = [];
        $source = new generated_image_source($this->downloader($calls));

        $file = $source->find(new file_reference(file_reference::KIND_IMAGE_PATH, '/data/chart'));

        $this->assertSame('chart.png', $file->get_filename());
    }

    /**
     * Other kinds of reference are not images of the service.
     */
    public function test_other_references_are_not_fetched(): void {
        $calls = [];
        $source = new generated_image_source($this->downloader($calls));

        $this->assertNull($source->find(new file_reference(file_reference::KIND_URL, '/data/chart.png')));
        $this->assertSame([], $calls);
    }

    /**
     * The sources are asked in order and the first file wins.
     */
    public function test_the_first_source_that_holds_the_file_wins(): void {
        $this->resetAfterTest();
        $calls = [];
        $image = new generated_image_source($this->downloader($calls));
        $none = new generated_file_source();
        $sources = new file_sources([$none, $image]);

        $found = $sources->find(new file_reference(file_reference::KIND_IMAGE_PATH, '/data/generated_images/a.png'));
        $missing = $sources->find(new file_reference(file_reference::KIND_PLACEHOLDER, '/b.png'));

        $this->assertSame('a.png', $found->get_filename());
        $this->assertNull($missing);
    }
}
