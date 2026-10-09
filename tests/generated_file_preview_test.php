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

use local_coursegen\utils\preview_draft_store;

/**
 * The files of an activity shown in its review preview from the draft area of the reviewer.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\generated_file_preview
 */
final class generated_file_preview_test extends \advanced_testcase {
    /** @var string The opaque uid of an activity. */
    private const UID = '7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10';

    /**
     * An entry of the template agent's generated_files: it has no thread_id and no size.
     *
     * @return array
     */
    private function entry(): array {
        return [
            'file_id' => '0a1b2c3d4e5f60718293a4b5c6d7e8f9.png',
            'filename' => 'forum-1a2b3c4d-1.png',
            'content_type' => 'image/png',
            'role' => 'main',
        ];
    }

    /**
     * A draft store whose downloader stores seven bytes.
     *
     * @return preview_draft_store
     */
    private function store(): preview_draft_store {
        $downloader = static function (string $thread, string $id, string $name, array $record) {
            return get_file_storage()->create_file_from_string($record, 'PNGDATA');
        };
        return new preview_draft_store(211, 'thread-1', $downloader);
    }

    /**
     * Every placeholder of a generated file, in any text, is written as the draft address the preview serves it from.
     */
    public function test_placeholders_become_draft_addresses_of_the_stored_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = $this->store();
        $parameters = [
            'name' => 'Forum',
            'introeditor' => ['text' => '<img src="@@PLUGINFILE@@/forum-1a2b3c4d-1.png" alt="x">', 'format' => 1],
            'mod_settings' => ['discussions' => [['message' => 'See @@PLUGINFILE@@/forum-1a2b3c4d-1.png']]],
            'count' => 3,
        ];

        $result = (new generated_file_preview($store))->addressed($parameters, [$this->entry()], self::UID);

        $address = $store->address(self::UID, 'forum-1a2b3c4d-1.png');
        $this->assertStringContainsString('/local/coursegen/preview_file.php/', $address);
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
        $this->setAdminUser();
        $parameters = ['introeditor' => ['text' => '<img src="@@PLUGINFILE@@/other.png">']];

        $result = (new generated_file_preview($this->store()))->addressed($parameters, [$this->entry()], self::UID);

        $this->assertSame($parameters, $result);
    }

    /**
     * An activity with no generated file is returned as it is.
     */
    public function test_an_activity_without_generated_files_is_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parameters = ['introeditor' => ['text' => '<img src="@@PLUGINFILE@@/x.png">']];

        $this->assertSame($parameters, (new generated_file_preview($this->store()))->addressed($parameters, [], self::UID));
    }

    /**
     * The file is stored in the draft area of the reviewer under the uid, and the address leads to it.
     */
    public function test_the_address_leads_to_the_file_in_the_user_draft_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = $this->store();
        (new generated_file_preview($store))->addressed(['a' => 'x'], [$this->entry()], self::UID);

        $record = $store->file_record(self::UID, 'forum-1a2b3c4d-1.png');
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
        $this->assertSame('/' . self::UID . '/', $stored->get_filepath());
    }

    /**
     * A resource whose file the run attached lists that file as its module shows it, served from the draft area.
     */
    public function test_a_resource_lists_the_attached_file_from_the_draft_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = $this->store();

        $rows = (new generated_file_preview($store))->resource_rows([$this->entry()], self::UID, 4511);

        $this->assertCount(1, $rows);
        $this->assertSame(4511, $rows[0]['contextid']);
        $this->assertSame('mod_resource', $rows[0]['component']);
        $this->assertSame('content', $rows[0]['filearea']);
        $this->assertSame('forum-1a2b3c4d-1.png', $rows[0]['filename']);
        $this->assertSame(7, $rows[0]['filesize']);
        $this->assertSame($store->address(self::UID, 'forum-1a2b3c4d-1.png'), $rows[0]['url']);
    }
}
