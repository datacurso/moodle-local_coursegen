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

namespace local_coursegen\local\service;

/**
 * The file a user leaves in a draft area.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\user_draft_file
 */
final class user_draft_file_test extends \advanced_testcase {
    /**
     * Put a file in a draft area of the current user.
     *
     * @param int $draftid Draft item id.
     * @param string $filename Name of the file.
     * @param string $content Bytes of the file.
     */
    private function put(int $draftid, string $filename, string $content = 'data'): void {
        global $USER;
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * The first file of the area is returned.
     */
    public function test_the_first_file_of_the_area_is_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = file_get_unused_draft_itemid();
        $this->put($draftid, 'a.pdf');

        $file = user_draft_file::first($draftid);

        $this->assertInstanceOf(\stored_file::class, $file);
        $name = $file->get_filename();
        $this->assertSame('a.pdf', $name);
    }

    /**
     * An area without files, or one that does not exist, gives null.
     */
    public function test_an_area_without_files_gives_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $unused = file_get_unused_draft_itemid();
        $none = user_draft_file::first($unused);
        $zero = user_draft_file::first(0);
        $this->assertNull($none);
        $this->assertNull($zero);
    }

    /**
     * The files of another user are not reachable through the area of the current user.
     */
    public function test_the_draft_of_another_user_is_not_reachable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = file_get_unused_draft_itemid();
        $this->put($draftid, 'a.pdf');
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $file = user_draft_file::first($draftid);
        $this->assertNull($file);
    }

    /**
     * Removing the area deletes every file in it and leaves other areas alone.
     */
    public function test_removing_the_area_deletes_its_files_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = file_get_unused_draft_itemid();
        $second = file_get_unused_draft_itemid();
        $this->put($first, 'a.pdf');
        $this->put($first, 'b.pdf');
        $this->put($second, 'c.pdf');

        user_draft_file::remove_all($first);

        $removed = user_draft_file::first($first);
        $kept = user_draft_file::first($second);
        $keptname = $kept->get_filename();
        $this->assertNull($removed);
        $this->assertSame('c.pdf', $keptname);
    }

    /**
     * Removing an area that is already empty does nothing.
     */
    public function test_removing_an_empty_area_does_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $draftid = file_get_unused_draft_itemid();
        user_draft_file::remove_all($draftid);

        $this->assertTrue(true);
    }
}
