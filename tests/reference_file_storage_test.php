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

use local_coursegen\local\reference\reference_file_storage;

/**
 * Where the files a teacher brings wait for the course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_storage
 */
final class reference_file_storage_test extends \advanced_testcase {
    /**
     * A file written to a scratch path, the way an upload arrives.
     *
     * @param string $content
     * @return string The path.
     */
    private function upload_path(string $content): string {
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * A staged file is found again by its slot, with its name and content.
     */
    public function test_a_staged_file_is_found_by_its_slot(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        reference_file_storage::stage((int) $user->id, 7, '12.1', 'guide.pdf', $this->upload_path('PDFDATA'));
        $file = reference_file_storage::staged_file((int) $user->id, 7, '12.1');

        $this->assertNotNull($file);
        $this->assertSame('guide.pdf', $file->get_filename());
        $this->assertSame('PDFDATA', $file->get_content());
        $this->assertSame('local_coursegen', $file->get_component());
        $this->assertSame('referencestaged', $file->get_filearea());
    }

    /**
     * A slot without a file has none.
     */
    public function test_an_empty_slot_has_no_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        $file = reference_file_storage::staged_file((int) $user->id, 7, '12.1');

        $this->assertNull($file);
    }

    /**
     * A new upload replaces the file of the slot instead of adding another.
     */
    public function test_staging_again_replaces_the_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;

        reference_file_storage::stage($userid, 7, '12.1', 'old.pdf', $this->upload_path('OLD'));
        reference_file_storage::stage($userid, 7, '12.1', 'new.pdf', $this->upload_path('NEW'));
        $file = reference_file_storage::staged_file($userid, 7, '12.1');
        $keys = reference_file_storage::staged_keys($userid, 7);

        $this->assertSame('new.pdf', $file->get_filename());
        $this->assertSame(['12.1'], $keys);
    }

    /**
     * Removing a slot's file empties it and leaves the other slots alone.
     */
    public function test_removing_empties_only_that_slot(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        reference_file_storage::stage($userid, 7, '12.1', 'a.pdf', $this->upload_path('A'));
        reference_file_storage::stage($userid, 7, '12.2', 'b.pdf', $this->upload_path('B'));

        reference_file_storage::remove($userid, 7, '12.1');
        $keys = reference_file_storage::staged_keys($userid, 7);

        $this->assertSame(['12.2'], $keys);
    }

    /**
     * Removing a slot that has no file does nothing.
     */
    public function test_removing_an_empty_slot_does_nothing(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        reference_file_storage::remove((int) $user->id, 7, '12.1');
        $keys = reference_file_storage::staged_keys((int) $user->id, 7);

        $this->assertSame([], $keys);
    }

    /**
     * Staged files are kept apart per template and per teacher.
     */
    public function test_staged_files_are_kept_apart_per_template_and_teacher(): void {
        $this->resetAfterTest(true);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        reference_file_storage::stage((int) $first->id, 7, '12.1', 'a.pdf', $this->upload_path('A'));

        $othertemplate = reference_file_storage::staged_keys((int) $first->id, 8);
        $otherteacher = reference_file_storage::staged_keys((int) $second->id, 7);

        $this->assertSame([], $othertemplate);
        $this->assertSame([], $otherteacher);
    }

    /**
     * Starting a generation moves the staged files to the session and empties the staging.
     */
    public function test_adopting_moves_the_files_to_the_session(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        reference_file_storage::stage($userid, 7, '12.1', 'a.pdf', $this->upload_path('A'));
        reference_file_storage::stage($userid, 7, '12.2', 'b.png', $this->upload_path('B'));

        reference_file_storage::adopt($userid, 7, 55);
        $first = reference_file_storage::session_file($userid, 55, '12.1');
        $second = reference_file_storage::session_file($userid, 55, '12.2');
        $staged = reference_file_storage::staged_keys($userid, 7);

        $this->assertSame('A', $first->get_content());
        $this->assertSame('b.png', $second->get_filename());
        $this->assertSame('referencefile', $first->get_filearea());
        $this->assertSame([], $staged);
    }

    /**
     * Adopting with nothing staged leaves the session without files.
     */
    public function test_adopting_nothing_gives_the_session_no_files(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        reference_file_storage::adopt((int) $user->id, 7, 55);
        $file = reference_file_storage::session_file((int) $user->id, 55, '12.1');

        $this->assertNull($file);
    }

    /**
     * Deleting a session deletes its files and nothing else.
     */
    public function test_deleting_a_session_deletes_only_its_files(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        reference_file_storage::stage($userid, 7, '12.1', 'a.pdf', $this->upload_path('A'));
        reference_file_storage::adopt($userid, 7, 55);
        reference_file_storage::stage($userid, 7, '12.1', 'again.pdf', $this->upload_path('B'));
        reference_file_storage::adopt($userid, 7, 56);

        reference_file_storage::delete_session($userid, 55);
        $deleted = reference_file_storage::session_file($userid, 55, '12.1');
        $kept = reference_file_storage::session_file($userid, 56, '12.1');

        $this->assertNull($deleted);
        $this->assertSame('again.pdf', $kept->get_filename());
    }

    /**
     * The address of a stored file is a pluginfile address of the plugin, carrying the slot and the name.
     */
    public function test_the_address_names_the_plugin_the_slot_and_the_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        reference_file_storage::stage((int) $user->id, 7, '12.1', 'my guide.pdf', $this->upload_path('A'));
        reference_file_storage::adopt((int) $user->id, 7, 55);
        $file = reference_file_storage::session_file((int) $user->id, 55, '12.1');

        $url = reference_file_storage::url_of($file);
        $address = $url->out(false);

        $this->assertStringContainsString('/pluginfile.php/', $address);
        $this->assertStringContainsString('/local_coursegen/referencefile/55/12.1/my%20guide.pdf', $address);
    }

    /**
     * Files older than the limit are purged in both areas, newer ones stay.
     */
    public function test_purging_deletes_only_the_old_files_of_both_areas(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        reference_file_storage::stage($userid, 7, '12.1', 'old-staged.pdf', $this->upload_path('A'));
        reference_file_storage::stage($userid, 8, '13.1', 'old-session.pdf', $this->upload_path('B'));
        reference_file_storage::adopt($userid, 8, 60);
        reference_file_storage::stage($userid, 9, '14.1', 'recent.pdf', $this->upload_path('C'));
        $select = "component = 'local_coursegen' AND filearea LIKE 'reference%' AND filename <> 'recent.pdf'";
        $DB->set_field_select('files', 'timecreated', 100, $select);

        $deleted = reference_file_storage::purge_older_than(time() - HOURSECS);

        $this->assertGreaterThanOrEqual(2, $deleted);
        $oldstaged = reference_file_storage::staged_keys($userid, 7);
        $oldsession = reference_file_storage::session_file($userid, 60, '13.1');
        $recent = reference_file_storage::staged_keys($userid, 9);
        $this->assertSame([], $oldstaged);
        $this->assertNull($oldsession);
        $this->assertSame(['14.1'], $recent);
    }

    /**
     * Deleting a user's files empties both areas for that user and leaves other users alone.
     */
    public function test_deleting_the_files_of_a_user_leaves_other_users_alone(): void {
        $this->resetAfterTest(true);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $firstid = (int) $first->id;
        $secondid = (int) $second->id;
        reference_file_storage::stage($firstid, 7, '12.1', 'a.pdf', $this->upload_path('A'));
        reference_file_storage::stage($firstid, 8, '13.1', 'b.pdf', $this->upload_path('B'));
        reference_file_storage::adopt($firstid, 8, 60);
        reference_file_storage::stage($secondid, 7, '12.1', 'c.pdf', $this->upload_path('C'));

        reference_file_storage::delete_user_files($firstid);
        $staged = reference_file_storage::staged_keys($firstid, 7);
        $session = reference_file_storage::session_file($firstid, 60, '13.1');
        $other = reference_file_storage::staged_keys($secondid, 7);

        $this->assertSame([], $staged);
        $this->assertNull($session);
        $this->assertSame(['12.1'], $other);
    }
}
