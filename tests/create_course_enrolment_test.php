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
use local_coursegen\local\service\create_course_service;

/**
 * Enrolment tests for the user who requested the generated course.
 *
 * core's create_course() builds the course but enrols nobody, so a course
 * generated here used to reach no "My courses" at all. The service repeats the
 * block /course/edit.php runs after its own create_course(), which is what
 * these tests pin — including the site-admin path, governed by
 * $CFG->enroladminnewcourse rather than by capabilities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_enrolment_test extends \advanced_testcase {
    /**
     * Build a planning session record owned by the given user.
     *
     * @param int $userid Owner of the planning session.
     * @param string $sessionid External session identifier.
     * @return course_session
     */
    private function create_session(int $userid, string $sessionid): course_session {
        $session = new course_session(0, (object) [
            'userid' => $userid,
            'session_id' => $sessionid,
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Create a course from a minimal result, with no activities.
     *
     * @param course_session $session Planning session.
     * @param string $shortname Course shortname.
     * @return array Service result.
     */
    private function create_course(course_session $session, string $shortname): array {
        return create_course_service::create_course($session, [
            'course_configuration' => [
                'fullname' => 'Curso de Agronomía',
                'shortname' => $shortname,
            ],
        ]);
    }

    /**
     * Roles the given user holds in the course context.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return string[] Role shortnames.
     */
    private function course_roles(int $courseid, int $userid): array {
        $roles = get_user_roles(\context_course::instance($courseid), $userid, false);

        return array_values(array_map(static fn($role): string => $role->shortname, $roles));
    }

    /**
     * A teacher who requests a course is enrolled in it with the creator role,
     * so the course they just generated shows up in their own course list.
     */
    public function test_the_requesting_user_is_enrolled_with_the_creator_role(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id, 'sess-enrol-001');

        $result = $this->create_course($session, 'agro-enrol-001');

        $this->assertTrue($result['success']);
        $context = \context_course::instance($result['courseid']);
        $this->assertTrue(is_enrolled($context, $user->id));
        $this->assertSame(['editingteacher'], $this->course_roles($result['courseid'], $user->id));
    }

    /**
     * A site admin is enrolled too when enroladminnewcourse is on: is_viewing()
     * is always true for admins, so only the setting can decide.
     */
    public function test_a_site_admin_is_enrolled_when_the_site_setting_allows_it(): void {
        $this->resetAfterTest();

        set_config('enroladminnewcourse', 1);
        $this->setAdminUser();
        $session = $this->create_session(get_admin()->id, 'sess-enrol-002');

        $result = $this->create_course($session, 'agro-enrol-002');

        $this->assertTrue($result['success']);
        $this->assertTrue(is_enrolled(\context_course::instance($result['courseid']), get_admin()->id));
    }

    /**
     * With enroladminnewcourse off, the admin keeps their site-wide access but
     * gains no enrolment — the same choice the site made for /course/edit.php.
     */
    public function test_a_site_admin_is_not_enrolled_when_the_site_setting_forbids_it(): void {
        $this->resetAfterTest();

        set_config('enroladminnewcourse', 0);
        $this->setAdminUser();
        $session = $this->create_session(get_admin()->id, 'sess-enrol-003');

        $result = $this->create_course($session, 'agro-enrol-003');

        $this->assertTrue($result['success']);
        $this->assertFalse(is_enrolled(\context_course::instance($result['courseid']), get_admin()->id));
    }

    /**
     * The enrolment is a side effect, never a reason to fail: with no creator
     * role configured the course is still created and reported as a success.
     */
    public function test_the_course_is_created_even_when_no_creator_role_is_configured(): void {
        $this->resetAfterTest();

        set_config('creatornewroleid', 0);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $session = $this->create_session($user->id, 'sess-enrol-004');

        $result = $this->create_course($session, 'agro-enrol-004');

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['courseid']);
        $this->assertFalse(is_enrolled(\context_course::instance($result['courseid']), $user->id));
    }
}
