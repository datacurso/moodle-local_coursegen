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

namespace local_coursegen\task;

use local_coursegen\local\space\space_file_storage;

/**
 * The task that deletes the files no generation used.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\task\purge_space_files
 */
final class purge_space_files_test extends \advanced_testcase {
    /**
     * A draft area holding one file.
     *
     * @param int $userid
     * @param string $name
     * @return int The draft item id.
     */
    private function draft_with(int $userid, string $name): int {
        $draftid = file_get_unused_draft_itemid();
        $context = \context_user::instance($userid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/', 'filename' => $name,
        ], 'A');
        return $draftid;
    }

    /**
     * A file past the time limit is deleted and a recent one stays.
     */
    public function test_the_task_deletes_only_the_stale_files(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->setUser($user);
        space_file_storage::store_draft($userid, 7, 12, $this->draft_with($userid, 'stale.pdf'));
        space_file_storage::store_draft($userid, 8, 13, $this->draft_with($userid, 'recent.pdf'));
        $stale = time() - purge_space_files::KEEP_FOR - HOURSECS;
        $select = "component = 'local_coursegen' AND filearea = :area AND itemid = 7";
        $DB->set_field_select('files', 'timecreated', $stale, $select, ['area' => space_file_storage::AREA]);

        $task = new purge_space_files();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame([], space_file_storage::files_of_session($userid, 7));
        $this->assertSame([13], array_keys(space_file_storage::files_of_session($userid, 8)));
    }

    /**
     * The task has a readable name.
     */
    public function test_the_task_has_a_name(): void {
        $task = new purge_space_files();

        $name = $task->get_name();

        $this->assertNotSame('', $name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
