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
 * Contract tests for the plan adjustment action sent to the AI service.
 *
 * The action handed to the AI service is captured through a mocked
 * ai_course_api_service, so no network request is ever performed. Loading the
 * external class pulls in lib/externallib.php, so each test runs in an
 * isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\course_planning_feedback
 *
 * @runTestsInSeparateProcesses
 */
final class course_planning_feedback_contract_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_course_planning_feedback.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_course_planning_feedback::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a planning session for the current admin and capture feedback.
     *
     * @param string|null $capturedthread Reference receiving the thread id sent.
     * @param array|null $capturedaction Reference receiving the pending action sent.
     * @return int Session record id.
     */
    private function prepare_session(?string &$capturedthread, ?array &$capturedaction): int {
        $session = new course_session(0, (object)[
            'userid' => get_admin()->id,
            'session_id' => 'thread-fb',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([]),
        ]);
        $session->create();

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['send_planning_feedback'])
            ->getMock();
        $service->method('send_planning_feedback')->willReturnCallback(
            function (string $sessionid, array $pendingaction) use (&$capturedthread, &$capturedaction): array {
                $capturedthread = $sessionid;
                $capturedaction = $pendingaction;
                return [];
            }
        );
        testable_course_planning_feedback::$mockservice = $service;

        return (int)$session->get('id');
    }

    /**
     * MDL-CTR-002: Each adjustment action travels with its action type, the
     * target ids, the parent when applicable, the position, the moved item,
     * the custom proposal text flag and the free-text instruction.
     */
    public function test_adjustment_action_structure_travels_complete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $capturedthread = null;
        $capturedaction = null;
        $recordid = $this->prepare_session($capturedthread, $capturedaction);

        $result = testable_course_planning_feedback::execute($recordid, [
            'action' => 'move_activity',
            'target_ids' => ['uuid-activity-1'],
            'parent_section_id' => 'uuid-section-2',
            'position' => 3,
            'moved_id' => 'uuid-activity-1',
            'proposal_custom' => true,
            'instruction' => 'Muevela al final de la segunda seccion',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('thread-fb', $capturedthread);

        $this->assertSame('move_activity', $capturedaction['action']);
        $this->assertSame(['uuid-activity-1'], $capturedaction['target_ids']);
        $this->assertSame('uuid-section-2', $capturedaction['parent_section_id']);
        $this->assertSame(3, $capturedaction['position']);
        $this->assertSame('uuid-activity-1', $capturedaction['moved_id']);
        $this->assertTrue((bool)$capturedaction['proposal_custom']);
        $this->assertSame('Muevela al final de la segunda seccion', $capturedaction['instruction']);
    }

    /**
     * MDL-CTR-002: Accepting the plan travels as an acceptance action with an
     * empty instruction and no targets.
     */
    public function test_accept_action_travels_with_empty_instruction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $capturedthread = null;
        $capturedaction = null;
        $recordid = $this->prepare_session($capturedthread, $capturedaction);

        $result = testable_course_planning_feedback::execute($recordid, ['action' => 'accept']);

        $this->assertTrue($result['success']);
        $this->assertSame('accept', $capturedaction['action']);
        $this->assertSame([], $capturedaction['target_ids']);
        $this->assertSame('', $capturedaction['instruction']);
        $this->assertNull($capturedaction['parent_section_id']);
        $this->assertNull($capturedaction['position']);
        $this->assertNull($capturedaction['moved_id']);
        $this->assertFalse((bool)$capturedaction['proposal_custom']);
    }
}
