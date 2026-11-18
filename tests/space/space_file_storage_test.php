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

namespace local_coursegen\local\space;

/**
 * Tests for space_file_storage.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_file_storage
 */
final class space_file_storage_test extends \advanced_testcase {
    /**
     * A draft area of a user holding one file.
     *
     * @param int $userid
     * @param string $name
     * @param string $content
     * @return int The draft item id.
     */
    private function draft_with(int $userid, string $name, string $content): int {
        $draftid = file_get_unused_draft_itemid();
        $context = \context_user::instance($userid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/', 'filename' => $name,
        ], $content);
        return $draftid;
    }

    /**
     * The first file of a draft area is found, and an empty or unused one has none.
     */
    public function test_draft_file_finds_the_file_of_a_draft_area(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $draftid = $this->draft_with((int) $user->id, 'guide.pdf', 'PDF');

        $file = space_file_storage::draft_file((int) $user->id, $draftid);

        $this->assertSame('guide.pdf', $file->get_filename());
        $this->assertNull(space_file_storage::draft_file((int) $user->id, $draftid + 999));
        $this->assertNull(space_file_storage::draft_file((int) $user->id, 0));
    }

    /**
     * A draft file is copied for a session and a cmid, and the copy outlives the draft.
     */
    public function test_store_draft_keeps_a_copy_per_session_and_cmid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->setUser($user);
        $draftid = $this->draft_with($userid, 'guide.pdf', 'PDF');

        $stored = space_file_storage::store_draft($userid, 7, 321, $draftid);
        get_file_storage()->delete_area_files(\context_user::instance($userid)->id, 'user', 'draft', $draftid);

        $files = space_file_storage::files_of_session($userid, 7);
        $this->assertSame('guide.pdf', $stored->get_filename());
        $this->assertSame([321], array_keys($files));
        $this->assertSame('PDF', $files[321]->get_content());
        $this->assertSame([], space_file_storage::files_of_session($userid, 8));
    }

    /**
     * A draft with no file cannot be stored.
     */
    public function test_store_draft_without_a_file_fails(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        space_file_storage::store_draft((int) $user->id, 7, 321, 555555);
    }

    /**
     * A session's files are deleted without touching another session.
     */
    public function test_delete_session_removes_only_that_session(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->setUser($user);
        space_file_storage::store_draft($userid, 7, 1, $this->draft_with($userid, 'a.pdf', 'A'));
        space_file_storage::store_draft($userid, 8, 1, $this->draft_with($userid, 'b.pdf', 'B'));

        space_file_storage::delete_session($userid, 7);

        $this->assertSame([], space_file_storage::files_of_session($userid, 7));
        $this->assertCount(1, space_file_storage::files_of_session($userid, 8));
    }

    /**
     * The files of a user are all deleted when the user is erased.
     */
    public function test_delete_user_files_removes_every_session(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->setUser($user);
        space_file_storage::store_draft($userid, 7, 1, $this->draft_with($userid, 'a.pdf', 'A'));
        space_file_storage::store_draft($userid, 8, 1, $this->draft_with($userid, 'b.pdf', 'B'));

        space_file_storage::delete_user_files($userid);

        $this->assertSame([], space_file_storage::files_of_session($userid, 7));
        $this->assertSame([], space_file_storage::files_of_session($userid, 8));
    }

    /**
     * Only the files older than the limit are purged.
     */
    public function test_purge_older_than_deletes_only_stale_files(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->setUser($user);
        space_file_storage::store_draft($userid, 7, 1, $this->draft_with($userid, 'old.pdf', 'A'));
        space_file_storage::store_draft($userid, 8, 1, $this->draft_with($userid, 'new.pdf', 'B'));
        $old = time() - DAYSECS;
        $select = "component = 'local_coursegen' AND filearea = :area AND itemid = 7";
        $DB->set_field_select('files', 'timecreated', $old, $select, ['area' => space_file_storage::AREA]);

        $deleted = space_file_storage::purge_older_than(time() - HOURSECS);

        $this->assertSame(1, $deleted);
        $this->assertSame([], space_file_storage::files_of_session($userid, 7));
        $this->assertCount(1, space_file_storage::files_of_session($userid, 8));
    }
}
