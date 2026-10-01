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
use core\context\system;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;

/**
 * Access guard tests for local_coursegen_pluginfile (syllabus serving).
 *
 * Syllabus files live in the SYSTEM context with the planning session id as
 * item id, so the file callback must reject any other context and only allow
 * the session owner or holders of local/coursegen:view_syllabus.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_coursegen_pluginfile
 * @covers     \local_coursegen\local\service\course_session_service::can_view_syllabus
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('local_coursegen_pluginfile')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\local_coursegen\local\service\course_session_service::class, 'can_view_syllabus')]
final class pluginfile_test extends \advanced_testcase {
    /**
     * Load lib.php in each test process.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/coursegen/lib.php');
        $this->resetAfterTest();
    }

    /**
     * Create a planning session for a course owned by the given user.
     *
     * @param int $courseid Course id.
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function create_session(int $courseid, int $userid): course_session {
        return $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_session([
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
    }

    /**
     * Store a syllabus file for a planning session.
     *
     * @param course_session $session Planning session.
     * @return void
     */
    private function create_syllabus_file(course_session $session): void {
        $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_syllabus_file($session);
    }

    /**
     * Requests against a course context are rejected: files live in the system context.
     */
    public function test_course_context_is_rejected(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $session = $this->create_session($course->id, $owner->id);
        $sessionid = (int)$session->get('id');
        $this->create_syllabus_file($session);
        $this->setUser($owner);

        $result = local_coursegen_pluginfile(
            $course,
            null,
            course::instance($course->id),
            'syllabus',
            [$sessionid, 'syllabus.pdf'],
            false,
            []
        );
        $this->assertFalse($result);
    }

    /**
     * An unexpected file area is rejected.
     */
    public function test_unknown_filearea_is_rejected(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $sessionid = (int)$this->create_session($course->id, $owner->id)->get('id');
        $this->setUser($owner);

        $result = local_coursegen_pluginfile(
            $course,
            null,
            system::instance(),
            'somethingelse',
            [$sessionid, 'syllabus.pdf'],
            false,
            []
        );
        $this->assertFalse($result);
    }

    /**
     * An item id that does not match any planning session is rejected.
     */
    public function test_unknown_session_is_rejected(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = local_coursegen_pluginfile(
            get_site(),
            null,
            system::instance(),
            'syllabus',
            [999999, 'syllabus.pdf'],
            false,
            []
        );
        $this->assertFalse($result);
    }

    /**
     * A user who neither owns the session nor holds view_syllabus is rejected.
     */
    public function test_non_owner_without_capability_is_rejected(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $intruder = $generator->create_user();
        $session = $this->create_session($course->id, $owner->id);
        $sessionid = (int)$session->get('id');
        $this->create_syllabus_file($session);
        $this->setUser($intruder);

        $result = local_coursegen_pluginfile(
            get_site(),
            null,
            system::instance(),
            'syllabus',
            [$sessionid, 'syllabus.pdf'],
            false,
            []
        );
        $this->assertFalse($result);
    }

    /**
     * The session owner may view the syllabus file of their own session.
     */
    public function test_owner_can_view_syllabus(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $sessionid = (int)$this->create_session($course->id, $owner->id)->get('id');

        $this->assertTrue(course_session_service::can_view_syllabus($sessionid, $owner->id));
    }

    /**
     * A non-owner holding local/coursegen:view_syllabus in the system context may view the file.
     */
    public function test_capability_holder_can_view_syllabus(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $reviewer = $generator->create_user();
        $sessionid = (int)$this->create_session($course->id, $owner->id)->get('id');

        $roleid = create_role('Syllabus reviewer', 'syllabusreviewer', '');
        $systemcontext = system::instance();
        assign_capability('local/coursegen:view_syllabus', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $reviewer->id, $systemcontext->id);

        $this->assertTrue(course_session_service::can_view_syllabus($sessionid, $reviewer->id));
    }

    /**
     * A non-owner without the capability may not view the file, nor anyone for a missing session.
     */
    public function test_access_denied_without_ownership_or_capability(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $intruder = $generator->create_user();
        $sessionid = (int)$this->create_session($course->id, $owner->id)->get('id');

        $this->assertFalse(course_session_service::can_view_syllabus($sessionid, $intruder->id));
        $this->assertFalse(course_session_service::can_view_syllabus(999999, $owner->id));
    }
}
