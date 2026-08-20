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
 * Tests for the resumable state snapshot of a course session.
 *
 * The AI service is mocked, so no network request is ever performed. Loading
 * the external class pulls in lib/externallib.php, so each test runs in an
 * isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_session_state
 *
 * @runTestsInSeparateProcesses
 */
final class get_course_session_state_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_get_course_session_state.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_get_course_session_state::$mockservice = null;
        parent::tearDown();
    }

    /**
     * MDL-INT-028: The state service returns the local session data together
     * with the service snapshot and the stream URL to resume.
     */
    public function test_state_returns_local_data_with_service_snapshot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $coursedata = [
            'local_coursegen_lang' => 'es',
            'local_coursegen_generate_images' => 1,
            'local_coursegen_context_type' => 'customprompt',
            'local_coursegen_custom_prompt' => 'Curso de quimica organica',
        ];
        $session = new course_session(0, (object)[
            'userid' => get_admin()->id,
            'session_id' => 'thread-state',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();

        $snapshot = [
            'conversation' => [
                ['role' => 'user', 'content' => 'Curso de quimica organica'],
                ['role' => 'assistant', 'content' => 'Plan propuesto'],
            ],
            'plan' => ['sections' => [['id' => 'uuid-1', 'name' => 'Introduccion', 'position' => 0]]],
            'pending_proposals' => [['id' => 'prop-1', 'summary' => 'Agregar una seccion de repaso']],
        ];

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_state', 'get_course_streaming_url'])
            ->getMock();
        $service->method('get_course_state')->willReturn($snapshot);
        $service->method('get_course_streaming_url')
            ->willReturn('https://ai.example.com/api/v1/course/stream/thread-state');
        testable_get_course_session_state::$mockservice = $service;

        $result = testable_get_course_session_state::execute((int)$session->get('id'));

        $this->assertTrue($result['success']);
        $this->assertSame((int)$session->get('id'), $result['recordid']);
        $this->assertSame('thread-state', $result['sessionid']);
        $this->assertSame('https://ai.example.com/api/v1/course/stream/thread-state', $result['streamingurl']);
        $this->assertSame(course_session::STATUS_PENDING, $result['sessionstatus']);
        $this->assertSame(0, $result['courseid']);
        $this->assertFalse($result['iscreated']);

        // The local session choices travel back for the wizard to restore.
        $this->assertSame($coursedata, json_decode($result['coursedatajson'], true));
    }

    /**
     * MDL-INT-028: The snapshot travels verbatim, so the conversation, the
     * plan and the pending proposals can be rebuilt exactly where they were.
     */
    public function test_snapshot_travels_verbatim_for_resume(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = new course_session(0, (object)[
            'userid' => get_admin()->id,
            'session_id' => 'thread-verbatim',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([]),
        ]);
        $session->create();

        $snapshot = [
            'conversation' => [['role' => 'user', 'content' => 'Solicitud con acentos: química']],
            'plan' => ['sections' => []],
            'pending_proposals' => [],
            'awaiting_approval' => true,
        ];

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_state', 'get_course_streaming_url'])
            ->getMock();
        $service->method('get_course_state')->willReturn($snapshot);
        $service->method('get_course_streaming_url')
            ->willReturn('https://ai.example.com/api/v1/course/stream/thread-verbatim');
        testable_get_course_session_state::$mockservice = $service;

        $result = testable_get_course_session_state::execute((int)$session->get('id'));

        $decoded = json_decode($result['snapshotjson'], true);
        $this->assertSame($snapshot, $decoded);
        $this->assertTrue($decoded['awaiting_approval']);
        $this->assertSame(
            'Solicitud con acentos: química',
            $decoded['conversation'][0]['content']
        );
    }
}
