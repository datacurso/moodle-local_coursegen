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

use core\context\course;
use core\exception\moodle_exception;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;

/**
 * Tests for the course planning session service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_session_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\course_session_service::class)]
final class course_session_service_test extends \advanced_testcase {
    /**
     * Create a planning session through the plugin generator.
     *
     * @param array $record Generator record overrides.
     * @return course_session
     */
    private function create_session(array $record): course_session {
        return $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_session($record);
    }

    /**
     * The owner gets their own session back.
     */
    public function test_get_user_session_returns_own_session(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id, 'session_id' => 'thread-own']);

        $found = course_session_service::get_user_session((int)$session->get('id'), (int)$owner->id);

        $this->assertEquals($session->get('id'), $found->get('id'));
        $this->assertSame('thread-own', $found->get('session_id'));
    }

    /**
     * A session owned by someone else is reported as not found, even to an admin.
     */
    public function test_get_user_session_rejects_foreign_session(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id]);

        try {
            course_session_service::get_user_session((int)$session->get('id'), (int)get_admin()->id);
            $this->fail('A foreign session must not be returned.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
            $this->assertSame('local_coursegen', $e->module);
        }
    }

    /**
     * A missing session id raises the same error.
     */
    public function test_get_user_session_rejects_missing_session(): void {
        $this->resetAfterTest();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_no_session_found', 'local_coursegen'));
        course_session_service::get_user_session(999999, (int)get_admin()->id);
    }

    /**
     * require_owned_session() returns the owner's session and is what get_user_session() delegates to.
     */
    public function test_require_owned_session_returns_own_session(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id, 'session_id' => 'thread-owned']);

        $found = course_session_service::require_owned_session((int)$session->get('id'), (int)$owner->id);

        $this->assertEquals($session->get('id'), $found->get('id'));
        $this->assertSame('thread-owned', $found->get('session_id'));
    }

    /**
     * A foreign session is reported as not found by require_owned_session(), even to an admin.
     */
    public function test_require_owned_session_rejects_foreign_session(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id]);

        try {
            course_session_service::require_owned_session((int)$session->get('id'), (int)get_admin()->id);
            $this->fail('A foreign session must not be returned.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
            $this->assertSame('local_coursegen', $e->module);
        }
    }

    /**
     * A missing session id raises the same error from require_owned_session().
     */
    public function test_require_owned_session_rejects_missing_session(): void {
        $this->resetAfterTest();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_no_session_found', 'local_coursegen'));
        course_session_service::require_owned_session(999999, (int)get_admin()->id);
    }

    /**
     * update_status() stores the new status and bumps timemodified.
     */
    public function test_update_status(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session(['status' => course_session::STATUS_PENDING, 'timemodified' => 1000]);

        course_session_service::update_status((int)$session->get('id'), course_session::STATUS_CREATING);

        $record = $DB->get_record('local_coursegen_course_sessions', ['id' => $session->get('id')], '*', MUST_EXIST);
        $this->assertEquals(course_session::STATUS_CREATING, $record->status);
        $this->assertGreaterThan(1000, $record->timemodified);
    }

    /**
     * In-progress sessions exclude created ones and are ordered newest first.
     */
    public function test_get_user_inprogress_sessions_excludes_created_by_default(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $other = $generator->create_user();

        $userid = (int)$user->id;
        $pending = $this->create_session(['userid' => $userid, 'status' => course_session::STATUS_PENDING, 'timecreated' => 100]);
        $creating = $this->create_session(['userid' => $userid, 'status' => course_session::STATUS_CREATING, 'timecreated' => 200]);
        $created = $this->create_session(['userid' => $userid, 'status' => course_session::STATUS_CREATED, 'timecreated' => 300]);
        $failed = $this->create_session(['userid' => $userid, 'status' => course_session::STATUS_FAILED, 'timecreated' => 400]);
        $this->create_session(['userid' => $other->id, 'status' => course_session::STATUS_PENDING, 'timecreated' => 500]);

        $sessions = course_session_service::get_user_inprogress_sessions($userid);

        $ids = array_map(static fn(course_session $session): int => (int)$session->get('id'), $sessions);
        $this->assertSame([(int)$failed->get('id'), (int)$creating->get('id'), (int)$pending->get('id')], $ids);
        $this->assertNotContains((int)$created->get('id'), $ids);
    }

    /**
     * Created sessions are included on request and the limit keeps the newest ones.
     */
    public function test_get_user_inprogress_sessions_with_created_and_limit(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $pending = $this->create_session(['userid' => $user->id, 'status' => course_session::STATUS_PENDING, 'timecreated' => 100]);
        $created = $this->create_session(['userid' => $user->id, 'status' => course_session::STATUS_CREATED, 'timecreated' => 300]);
        $failed = $this->create_session(['userid' => $user->id, 'status' => course_session::STATUS_FAILED, 'timecreated' => 400]);

        $all = course_session_service::get_user_inprogress_sessions((int)$user->id, 0, true);
        $ids = array_map(static fn(course_session $session): int => (int)$session->get('id'), $all);
        $this->assertSame([(int)$failed->get('id'), (int)$created->get('id'), (int)$pending->get('id')], $ids);

        $limited = course_session_service::get_user_inprogress_sessions((int)$user->id, 2, true);
        $ids = array_map(static fn(course_session $session): int => (int)$session->get('id'), $limited);
        $this->assertSame([(int)$failed->get('id'), (int)$created->get('id')], $ids);

        $this->assertSame([], course_session_service::get_user_inprogress_sessions((int)$user->id + 1000));
    }

    /**
     * An admin may view any syllabus through the system capability, but not one of a missing session.
     */
    public function test_can_view_syllabus_for_admin(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id]);
        $adminid = (int)get_admin()->id;

        $this->assertTrue(course_session_service::can_view_syllabus((int)$session->get('id'), $adminid));
        $this->assertFalse(course_session_service::can_view_syllabus(999999, $adminid));
    }

    /**
     * The view_syllabus capability only counts in the system context: a course-level grant is not enough.
     */
    public function test_can_view_syllabus_ignores_course_level_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $reviewer = $generator->create_user();
        $course = $generator->create_course();
        $session = $this->create_session(['userid' => $owner->id, 'courseid' => $course->id]);

        $roleid = $generator->create_role();
        assign_capability('local/coursegen:view_syllabus', CAP_ALLOW, $roleid, course::instance($course->id));
        role_assign($roleid, $reviewer->id, course::instance($course->id));

        $this->assertFalse(course_session_service::can_view_syllabus((int)$session->get('id'), (int)$reviewer->id));
    }

    /**
     * A user id of zero (nobody) can never view a syllabus, even for a session of user zero's.
     */
    public function test_can_view_syllabus_rejects_nobody(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session(['userid' => $owner->id]);

        $this->assertFalse(course_session_service::can_view_syllabus((int)$session->get('id'), 0));
    }
}
