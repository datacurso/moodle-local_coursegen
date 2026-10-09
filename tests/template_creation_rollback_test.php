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

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\template_creation_rollback;

/**
 * Unit tests for template_creation_rollback.
 *
 * A creation that fails half way must not leave a course behind: the professor would find a course with
 * missing activities, or an empty section, that only shows up well after the error.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_creation_rollback
 *
 * @runTestsInSeparateProcesses
 */
final class template_creation_rollback_test extends \advanced_testcase {
    /**
     * A session of the current user, optionally already pointing at a course.
     *
     * @param int|null $courseid The course the creation had reached, null when none.
     * @return course_session The stored session.
     */
    private function session(?int $courseid): course_session {
        global $USER;

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => '11111111-2222-4333-8444-555555555555',
            'status' => course_session::STATUS_CREATING,
            'courseid' => $courseid,
            'coursedata' => json_encode(['templateid' => 1]),
        ]);
        $session->create();
        return $session;
    }

    /**
     * The course the creation had reached is deleted, with everything in it.
     */
    public function test_the_half_created_course_is_deleted(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $session = $this->session((int) $course->id);

        template_creation_rollback::undo($session);

        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['course' => $course->id]));
    }

    /**
     * The session is left as a failed one that points at no course, so a retry starts clean.
     */
    public function test_the_session_is_marked_failed_and_points_at_no_course(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $course->id);

        template_creation_rollback::undo($session);

        $stored = new course_session((int) $session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int) $stored->get('status'));
        $this->assertNull($stored->get('courseid'));
    }

    /**
     * When the creation failed before any course existed there is nothing to delete and the session is still marked.
     */
    public function test_a_session_without_a_course_is_only_marked_failed(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $before = $DB->count_records('course');
        $session = $this->session(null);

        template_creation_rollback::undo($session);

        $stored = new course_session((int) $session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int) $stored->get('status'));
        $this->assertSame($before, $DB->count_records('course'));
    }

    /**
     * A course that was already deleted by someone else does not make the rollback fail.
     */
    public function test_a_course_that_is_already_gone_is_not_an_error(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $course->id);
        delete_course($course, false);

        template_creation_rollback::undo($session);

        $stored = new course_session((int) $session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int) $stored->get('status'));
    }

    /**
     * Only the course of the session goes: any other course stays.
     */
    public function test_other_courses_are_not_touched(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $mine->id);

        template_creation_rollback::undo($session);

        $this->assertTrue($DB->record_exists('course', ['id' => $other->id]));
    }

    /**
     * The rollback can be repeated: a second call finds nothing to delete and changes nothing.
     */
    public function test_the_rollback_is_idempotent(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $course->id);

        template_creation_rollback::undo($session);
        template_creation_rollback::undo($session);

        $stored = new course_session((int) $session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int) $stored->get('status'));
    }
}
