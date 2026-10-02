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
use local_coursegen\utils\mold_file_copier;

/**
 * The file a teacher brought is copied into the draft of the field that holds its address.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\mold_file_copier
 */
final class reference_file_copy_test extends \advanced_testcase {
    /**
     * Bring a file for place 12.1 of template 7 to session 55 of a user and give its address.
     *
     * @param \stdClass $user
     * @param string $name
     * @return string
     */
    private function brought_file_address(\stdClass $user, string $name): string {
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'BROUGHT');
        reference_file_storage::stage((int) $user->id, 7, '12.1', $name, $path);
        reference_file_storage::adopt((int) $user->id, 7, 55);
        $file = reference_file_storage::session_file((int) $user->id, 55, '12.1');
        $url = reference_file_storage::url_of($file);
        return $url->out(false);
    }

    /**
     * The draft files of the current user, keyed by name.
     *
     * @param int $draftitemid
     * @return \stored_file[]
     */
    private function draft_files(int $draftitemid): array {
        global $USER;
        $context = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);
        $byname = [];
        foreach ($files as $file) {
            $byname[$file->get_filename()] = $file;
        }
        return $byname;
    }

    /**
     * The address of the teacher's own file becomes a placeholder and the file lands in the draft.
     *
     * @dataProvider attribute_provider
     * @param string $attribute
     */
    public function test_the_brought_file_is_copied_for_every_file_attribute(string $attribute): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $address = $this->brought_file_address($user, 'My guide.pdf');
        $draftitemid = file_get_unused_draft_itemid();

        $text = '<object ' . $attribute . '="' . $address . '"></object>';

        $result = mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid, 99);

        $this->assertSame('<object ' . $attribute . '="@@PLUGINFILE@@/My%20guide.pdf"></object>', $result);
        $files = $this->draft_files($draftitemid);
        $this->assertSame('BROUGHT', $files['My guide.pdf']->get_content());
    }

    /**
     * The attributes that can hold a file.
     *
     * @return array
     */
    public static function attribute_provider(): array {
        return [['src'], ['href'], ['data'], ['poster']];
    }

    /**
     * The source course given to the copier does not stop the teacher's own file.
     */
    public function test_the_brought_file_is_not_bound_to_the_template_course(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $address = $this->brought_file_address($user, 'guide.pdf');
        $draftitemid = file_get_unused_draft_itemid();

        $result = mold_file_copier::copy_pluginfile_urls_to_draft('<img src="' . $address . '">', $draftitemid, 12345);

        $this->assertSame('<img src="@@PLUGINFILE@@/guide.pdf">', $result);
    }

    /**
     * Another user's brought file is never copied.
     */
    public function test_the_brought_file_of_another_teacher_is_not_copied(): void {
        $this->resetAfterTest(true);
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $address = $this->brought_file_address($owner, 'guide.pdf');
        $this->setUser($other);
        $draftitemid = file_get_unused_draft_itemid();
        $text = '<img src="' . $address . '">';

        $result = mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid);

        $this->assertSame($text, $result);
        $this->assertSame([], $this->draft_files($draftitemid));
    }

    /**
     * A file the teacher brought only for staging is not a file a generation uses.
     */
    public function test_a_staged_file_is_not_copied(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'STAGED');
        $file = reference_file_storage::stage((int) $user->id, 7, '12.1', 'staged.pdf', $path);
        $url = reference_file_storage::url_of($file);
        $draftitemid = file_get_unused_draft_itemid();
        $text = '<img src="' . $url->out(false) . '">';

        $result = mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid);

        $this->assertSame($text, $result);
    }
}
