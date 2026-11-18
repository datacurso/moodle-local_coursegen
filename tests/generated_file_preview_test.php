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

namespace local_coursegen\local\preview;

use local_coursegen\utils\generated_file_cache;

/**
 * The files the AI service made are addressed in the review preview of the activity they belong to.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\generated_file_preview
 * @covers     \local_coursegen\local\service\generated_file_server
 */
final class generated_file_preview_test extends \advanced_testcase {
    /**
     * An entry of the result's generated_files.
     *
     * @return array
     */
    private function entry(): array {
        return [
            'filename' => 'forum-1a2b3c4d-1.png',
            'mimetype' => 'image/png',
            'size' => 7,
            'thread_id' => 'thread-1',
            'file_id' => '0a1b2c3d4e5f60718293a4b5c6d7e8f9.png',
        ];
    }

    /**
     * A cache whose downloader stores seven bytes.
     *
     * @return generated_file_cache
     */
    private function cache(): generated_file_cache {
        return new generated_file_cache(static function (string $thread, string $id, string $name, array $record) {
            return get_file_storage()->create_file_from_string($record, 'PNGDATA');
        });
    }

    /**
     * Every placeholder of a generated file, in any text, is written as the address the preview serves it from.
     */
    public function test_placeholders_become_addresses_of_the_stored_file(): void {
        $this->resetAfterTest();
        $parameters = [
            'name' => 'Forum',
            'introeditor' => ['text' => '<img src="@@PLUGINFILE@@/forum-1a2b3c4d-1.png" alt="x">', 'format' => 1],
            'mod_settings' => ['discussions' => [['message' => 'See @@PLUGINFILE@@/forum-1a2b3c4d-1.png']]],
            'count' => 3,
        ];

        $result = (new generated_file_preview($this->cache()))->addressed($parameters, [$this->entry()]);

        $address = (string) new \moodle_url('/pluginfile.php/1/local_coursegen/generatedfiles/0/thread-1/forum-1a2b3c4d-1.png');
        $this->assertSame('<img src="' . $address . '" alt="x">', $result['introeditor']['text']);
        $this->assertSame('See ' . $address, $result['mod_settings']['discussions'][0]['message']);
        $this->assertSame(3, $result['count']);
        $this->assertSame('Forum', $result['name']);
    }

    /**
     * Placeholders of files that are not generated ones are left for the rest of the preview.
     */
    public function test_other_placeholders_are_left_alone(): void {
        $this->resetAfterTest();
        $parameters = ['introeditor' => ['text' => '<img src="@@PLUGINFILE@@/other.png">']];

        $result = (new generated_file_preview($this->cache()))->addressed($parameters, [$this->entry()]);

        $this->assertSame($parameters, $result);
    }

    /**
     * An activity with no generated file is returned as it is and nothing is downloaded.
     */
    public function test_an_activity_without_generated_files_is_untouched(): void {
        $parameters = ['introeditor' => ['text' => '<img src="@@PLUGINFILE@@/x.png">']];

        $this->assertSame($parameters, (new generated_file_preview())->addressed($parameters, []));
    }

    /**
     * The file is stored, and the address leads to it.
     */
    public function test_the_address_leads_to_the_stored_file(): void {
        $this->resetAfterTest();
        (new generated_file_preview($this->cache()))->addressed(['a' => 'x'], [$this->entry()]);

        $record = generated_file_cache::file_record($this->entry());
        $stored = get_file_storage()->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );

        $this->assertNotFalse($stored);
        $this->assertSame('PNGDATA', $stored->get_content());
    }
}
