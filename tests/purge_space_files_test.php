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

use local_coursegen\local\reference\reference_file_storage;

/**
 * The task that deletes the files no generation used.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\task\purge_reference_files
 */
final class purge_reference_files_test extends \advanced_testcase {
    /**
     * A file past the time limit is deleted and a recent one stays.
     */
    public function test_the_task_deletes_only_the_stale_files(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'A');
        reference_file_storage::stage($userid, 7, '12.1', 'stale.pdf', $path);
        reference_file_storage::stage($userid, 8, '13.1', 'recent.pdf', $path);
        $stale = time() - purge_reference_files::KEEP_FOR - HOURSECS;
        $select = "component = 'local_coursegen' AND filearea = 'referencestaged' AND itemid = 7";
        $DB->set_field_select('files', 'timecreated', $stale, $select);

        $task = new purge_reference_files();
        ob_start();
        $task->execute();
        ob_end_clean();
        $stalekeys = reference_file_storage::staged_keys($userid, 7);
        $recentkeys = reference_file_storage::staged_keys($userid, 8);

        $this->assertSame([], $stalekeys);
        $this->assertSame(['13.1'], $recentkeys);
    }

    /**
     * The task has a readable name.
     */
    public function test_the_task_has_a_name(): void {
        $task = new purge_reference_files();

        $name = $task->get_name();

        $this->assertNotSame('', $name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
