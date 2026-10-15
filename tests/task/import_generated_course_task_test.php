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

use local_coursegen\local\models\course_session;

/**
 * Tests for the adhoc task that imports a generated course into an existing one.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\task\import_generated_course_task
 */
final class import_generated_course_task_test extends \advanced_testcase {
    /**
     * Create a target course, a staging course with one module and a session pointing at the staging course.
     *
     * @param int $userid Session owner.
     * @return array{0: \stdClass, 1: \stdClass, 2: course_session}
     */
    private function create_fixture(int $userid): array {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $target = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'TASK-ORIG',
            'fullname' => 'Task original',
        ]);
        $target->id = (int)$target->id;
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original page']);
        $staging = $generator->create_course(['category' => $category->id, 'shortname' => 'task-stage']);
        $staging->id = (int)$staging->id;
        $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page']);

        $session = new course_session(0, (object) [
            'courseid' => $staging->id,
            'userid' => $userid,
            'session_id' => 'thread-task-1',
            'status' => course_session::STATUS_CREATED,
            'coursedata' => json_encode(['fullname' => 'Planned']),
        ]);
        $session->create();

        return [$target, $staging, $session];
    }

    /**
     * Queueing stores the queued state in the session and one adhoc task carrying the ids and the user.
     */
    public function test_queue_stores_queued_state_and_adhoc_task(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = get_admin()->id;
        [$target, $staging, $session] = $this->create_fixture($adminid);

        import_generated_course_task::queue($session->get('id'), $staging->id, $target->id, $adminid);

        $session = new course_session($session->get('id'));
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        $this->assertSame('queued', $coursedata['import']['state']);
        $this->assertSame($target->id, $coursedata['import']['targetcourseid']);
        $this->assertSame($staging->id, $coursedata['import']['stagingcourseid']);
        $this->assertSame('Planned', $coursedata['fullname']);

        $tasks = \core\task\manager::get_adhoc_tasks(import_generated_course_task::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int)$adminid, (int)$task->get_userid());
        $data = $task->get_custom_data();
        $this->assertSame((int)$session->get('id'), (int)$data->sessionid);
        $this->assertSame($staging->id, (int)$data->stagingcourseid);
        $this->assertSame($target->id, (int)$data->targetcourseid);
    }

    /**
     * Executing the task runs the import: the copy exists with the original names and the session points at it.
     */
    public function test_execute_runs_the_import(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = get_admin()->id;
        [$target, $staging, $session] = $this->create_fixture($adminid);

        $task = new import_generated_course_task();
        $task->set_custom_data([
            'sessionid' => $session->get('id'),
            'stagingcourseid' => $staging->id,
            'targetcourseid' => $target->id,
        ]);
        $task->set_userid($adminid);
        $task->execute();

        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_CREATED, (int)$session->get('status'));
        $copy = $DB->get_record('course', ['id' => $session->get('courseid')], '*', MUST_EXIST);
        $this->assertNotEquals($target->id, $copy->id);
        $this->assertSame('TASK-ORIG', $copy->shortname);
        $this->assertSame('Task original', $copy->fullname);
        $this->assertFalse($DB->record_exists('course', ['id' => $staging->id]));
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        $this->assertSame('done', $coursedata['import']['state']);
    }

    /**
     * Cron must never retry a failed import blindly: a rename already happened.
     */
    public function test_task_is_never_retried(): void {
        $task = new import_generated_course_task();
        $this->assertFalse($task->retry_until_success());
        $this->assertSame(get_string('task_import_generated_course', 'local_coursegen'), $task->get_name());
    }
}
