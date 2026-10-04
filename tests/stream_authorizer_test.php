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

use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\module_job_service;
use local_coursegen\local\streaming\stream_authorizer;
use local_coursegen\local\streaming\stream_kind;

/**
 * Unit tests for the ownership and capability check of the streams.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\stream_authorizer
 */
final class stream_authorizer_test extends \advanced_testcase {
    /**
     * Course planning stream of the owner who holds the capabilities is allowed.
     */
    public function test_owner_with_capabilities_may_read_a_course_stream(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_course_creation($user);
        $this->create_planning_session($user, 'thread-1');
        $this->setUser($user);

        (new stream_authorizer())->authorize(stream_kind::COURSE, 'thread-1');

        $this->assertTrue(true);
    }

    /**
     * Another user cannot read the planning stream of a session they do not own.
     */
    public function test_other_user_cannot_read_a_course_stream(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $intruder = $this->getDataGenerator()->create_user();
        $this->grant_course_creation($intruder);
        $this->create_planning_session($owner, 'thread-1');
        $this->setUser($intruder);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_no_session_found', 'local_coursegen'));

        (new stream_authorizer())->authorize(stream_kind::COURSE, 'thread-1');
    }

    /**
     * An unknown thread has no stream.
     */
    public function test_unknown_course_thread_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_course_creation($user);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);

        (new stream_authorizer())->authorize(stream_kind::COURSE, 'missing');
    }

    /**
     * Owning the session is not enough without the capabilities of the paid generation.
     */
    public function test_owner_without_capabilities_cannot_read_a_course_stream(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_planning_session($user, 'thread-1');
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);

        (new stream_authorizer())->authorize(stream_kind::COURSE, 'thread-1');
    }

    /**
     * The teacher who started an activity job may read its stream.
     */
    public function test_teacher_may_read_the_stream_of_their_activity_job(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        module_job_service::create_job($course->id, $teacher->id, 'job-1', 0, null, null, null, null);
        $this->setUser($teacher);

        (new stream_authorizer())->authorize(stream_kind::ACTIVITY, 'job-1');

        $this->assertTrue(true);
    }

    /**
     * Another teacher of the same course cannot read a job they did not start.
     */
    public function test_other_teacher_cannot_read_an_activity_stream(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        module_job_service::create_job($course->id, $owner->id, 'job-1', 0, null, null, null, null);
        $this->setUser($other);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_no_module_job_found', 'local_coursegen'));

        (new stream_authorizer())->authorize(stream_kind::ACTIVITY, 'job-1');
    }

    /**
     * A user who lost the right to edit the course can no longer read the stream of their old job.
     */
    public function test_user_who_lost_the_capability_cannot_read_an_activity_stream(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        module_job_service::create_job($course->id, $student->id, 'job-1', 0, null, null, null, null);
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        (new stream_authorizer())->authorize(stream_kind::ACTIVITY, 'job-1');
    }

    /**
     * A user who is not enrolled in the course of the job is refused.
     */
    public function test_user_not_enrolled_in_the_course_is_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        module_job_service::create_job($course->id, $user->id, 'job-1', 0, null, null, null, null);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);

        (new stream_authorizer())->authorize(stream_kind::ACTIVITY, 'job-1');
    }

    /**
     * A stream kind that does not exist is refused.
     */
    public function test_unknown_kind_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_stream_unknown_kind', 'local_coursegen'));

        (new stream_authorizer())->authorize('template', 'thread-1');
    }

    /**
     * Give the user the capabilities to start a course planning at the system level.
     *
     * @param \stdClass $user User.
     */
    private function grant_course_creation(\stdClass $user): void {
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $context->id, true);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
    }

    /**
     * Store a planning session for the user.
     *
     * @param \stdClass $user Owner.
     * @param string $threadid External session identifier.
     */
    private function create_planning_session(\stdClass $user, string $threadid): void {
        $data = (object) ['fullname' => 'Course'];
        course_session_service::create_from_form_data($data, (int) $user->id, $threadid);
    }
}
