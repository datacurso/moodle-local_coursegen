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
use local_coursegen\local\service\ai_course_api_service;

/**
 * Ownership tests for the course planning session endpoints.
 *
 * Every endpoint is exercised with a session belonging to another user; the
 * AI service is mocked and must never be reached. Loading the external
 * classes pulls in lib/externallib.php, so each test runs in an isolated
 * process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_session_state
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\create_course
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 *
 * @runTestsInSeparateProcesses
 */
final class course_session_ownership_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixtures in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_get_course_session_state.php');
        require_once(__DIR__ . '/fixtures/testable_course_planning_feedback.php');
        require_once(__DIR__ . '/fixtures/testable_get_course_settings.php');
        require_once(__DIR__ . '/fixtures/testable_create_course.php');
        require_once(__DIR__ . '/fixtures/testable_courseai_syllabus_upload.php');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_get_course_session_state::$mockservice = null;
        testable_course_planning_feedback::$mockservice = null;
        testable_get_course_settings::$mockservice = null;
        testable_create_course::$mockservice = null;
        testable_courseai_syllabus_upload::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a planning session persistent owned by the given user.
     *
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function make_session(int $userid): course_session {
        $session = new course_session(0, (object)[
            'userid' => $userid,
            'session_id' => 'thread-owner',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['local_coursegen_context_type' => 'customprompt']),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Build an ai_course_api_service mock that must never be reached.
     *
     * @param string[] $methods Service methods to guard.
     * @return ai_course_api_service
     */
    private function untouchable_service(array $methods): ai_course_api_service {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
        foreach ($methods as $method) {
            $service->expects($this->never())->method($method);
        }

        return $service;
    }

    /**
     * Set up an owner with a session and log in an intruder user.
     *
     * @return int Session record id owned by the other user.
     */
    private function prepare_foreign_session(): int {
        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $session = $this->make_session((int)$owner->id);

        $intruder = $generator->create_user();
        $this->setUser($intruder);

        return (int)$session->get('id');
    }

    /**
     * MDL-INT-004: Querying the state of another user's session is rejected
     * with a clear error and the AI service is never reached.
     */
    public function test_state_query_rejects_foreign_session(): void {
        $this->resetAfterTest();

        $recordid = $this->prepare_foreign_session();
        testable_get_course_session_state::$mockservice = $this->untouchable_service(
            ['get_course_state', 'get_course_streaming_url']
        );

        try {
            testable_get_course_session_state::execute($recordid);
            $this->fail('A foreign session must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
        }
    }

    /**
     * MDL-INT-004: Sending plan adjustments on another user's session is
     * rejected with a clear error and the AI service is never reached.
     */
    public function test_feedback_rejects_foreign_session(): void {
        $this->resetAfterTest();

        $recordid = $this->prepare_foreign_session();
        testable_course_planning_feedback::$mockservice = $this->untouchable_service(['send_planning_feedback']);

        try {
            testable_course_planning_feedback::execute($recordid, ['action' => 'accept']);
            $this->fail('A foreign session must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
        }
    }

    /**
     * MDL-INT-004: Querying the generated course settings of another user's
     * session is rejected with a clear error.
     */
    public function test_settings_query_rejects_foreign_session(): void {
        $this->resetAfterTest();

        $recordid = $this->prepare_foreign_session();
        testable_get_course_settings::$mockservice = $this->untouchable_service(['get_course_result']);

        try {
            testable_get_course_settings::execute($recordid);
            $this->fail('A foreign session must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
        }
    }

    /**
     * MDL-INT-004: Creating the course from another user's session is rejected
     * with a clear error and no course is created.
     */
    public function test_create_course_rejects_foreign_session(): void {
        global $DB;

        $this->resetAfterTest();

        $recordid = $this->prepare_foreign_session();
        testable_create_course::$mockservice = $this->untouchable_service(['get_course_result']);
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute($recordid);
            $this->fail('A foreign session must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
        }
        $this->assertDebuggingNotCalled();

        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }

    /**
     * MDL-INT-004: Uploading a syllabus to another user's session is rejected
     * and nothing is stored or sent.
     */
    public function test_syllabus_upload_rejects_foreign_session(): void {
        global $USER;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $session = $this->make_session((int)$owner->id);
        $recordid = (int)$session->get('id');

        // The intruder holds the flow capabilities, so only ownership blocks it.
        $systemcontext = \context_system::instance();
        $intruder = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $intruder->id, $systemcontext->id);
        $this->setUser($intruder);

        testable_courseai_syllabus_upload::$mockservice = $this->untouchable_service(['upload_syllabus']);

        // A real draft file, to prove the rejection happens before any save.
        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $fs->create_file_from_string((object)[
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake syllabus');

        $result = testable_courseai_syllabus_upload::execute($recordid, $draftitemid);

        $this->assertFalse($result['success']);

        // Nothing was stored in the plugin syllabus area for that session.
        $files = $fs->get_area_files($systemcontext->id, 'local_coursegen', 'syllabus', $recordid, 'id', false);
        $this->assertCount(0, $files);
    }
}
