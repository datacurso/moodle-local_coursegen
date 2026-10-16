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

use local_coursegen\external\get_course_session_state;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;

/**
 * Contract tests for the import fields of the get_course_session_state web service (ticket #9539).
 *
 * The import bookkeeping lives in the "import" object of the session
 * coursedata JSON; the web service exposes its state, a localised message and
 * the copy course once the import is done. The AI service is mocked through
 * the testable subclass. The fixture loads lib/externallib.php, so each test
 * runs in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_course_session_state
 *
 * @runTestsInSeparateProcesses
 */
final class get_course_session_state_import_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process and inject the mock API service.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        require_once(__DIR__ . '/fixtures/testable_get_course_session_state.php');

        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_state', 'get_course_streaming_url'])
            ->getMock();
        $service->method('get_course_state')->willReturn(['status' => 'completed']);
        $service->method('get_course_streaming_url')->willReturn('https://example.com/stream/thread-state-1');
        testable_get_course_session_state::$mockservice = $service;
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_get_course_session_state::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a session owned by the admin with the given coursedata bag.
     *
     * @param array $coursedata Course data JSON bag.
     * @param int $status Session status.
     * @param int|null $courseid Course the session points at.
     * @return course_session
     */
    private function create_session(array $coursedata, int $status, ?int $courseid): course_session {
        $session = new course_session(0, (object) [
            'courseid' => $courseid,
            'userid' => (int) get_admin()->id,
            'session_id' => 'thread-state-1',
            'status' => $status,
            'coursedata' => json_encode($coursedata, JSON_UNESCAPED_UNICODE),
        ]);
        $session->create();
        return $session;
    }

    /**
     * Call the web service and validate the result against the declared return structure.
     *
     * @param int $recordid Session record id.
     * @return array
     */
    private function call(int $recordid): array {
        $result = testable_get_course_session_state::execute($recordid);
        return \external_api::clean_returnvalue(get_course_session_state::execute_returns(), $result);
    }

    /**
     * Import states that carry no copy course URL.
     *
     * @return array[]
     */
    public static function pending_state_provider(): array {
        return [
            'queued' => ['queued', 'import_queued'],
            'running' => ['running', 'import_running'],
            'failed' => ['failed', 'import_failed'],
        ];
    }

    /**
     * Queued, running and failed imports expose their state and message, with no copy course.
     *
     * @dataProvider pending_state_provider
     * @param string $state Import state stored in the session JSON.
     * @param string $stringkey Lang key of the expected message.
     */
    public function test_pending_import_states_are_exposed(string $state, string $stringkey): void {
        $this->setAdminUser();
        $staging = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();
        $session = $this->create_session([
            'fullname' => 'Planned',
            'import' => [
                'state' => $state,
                'targetcourseid' => (int) $target->id,
                'stagingcourseid' => (int) $staging->id,
                'copycourseid' => 0,
            ],
        ], course_session::STATUS_IMPORTING, (int) $staging->id);

        $result = $this->call((int) $session->get('id'));

        $this->assertTrue($result['success']);
        $this->assertSame($state, $result['importstate']);
        $this->assertSame(get_string($stringkey, 'local_coursegen'), $result['importmessage']);
        $this->assertSame(0, $result['copycourseid']);
        $this->assertArrayNotHasKey('copycourseurl', $result);
        $this->assertSame((int) $staging->id, $result['courseid']);
        $this->assertSame(course_session::STATUS_IMPORTING, $result['sessionstatus']);
    }

    /**
     * A done import exposes the copy course id and URL, and the session course id is the copy.
     */
    public function test_done_import_exposes_copy_course(): void {
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_course();
        $session = $this->create_session([
            'fullname' => 'Planned',
            'import' => [
                'state' => 'done',
                'targetcourseid' => (int) $target->id,
                'stagingcourseid' => 0,
                'copycourseid' => (int) $copy->id,
            ],
        ], course_session::STATUS_CREATED, (int) $copy->id);

        $result = $this->call((int) $session->get('id'));

        $this->assertSame('done', $result['importstate']);
        $this->assertSame(get_string('import_completed', 'local_coursegen'), $result['importmessage']);
        $this->assertSame((int) $copy->id, $result['copycourseid']);
        $this->assertSame(course_get_url($copy->id)->out(false), $result['copycourseurl']);
        $this->assertSame((int) $copy->id, $result['courseid']);
        $this->assertTrue($result['iscreated']);
    }

    /**
     * A session that never queued an import reports an empty import state.
     */
    public function test_session_without_import_reports_empty_state(): void {
        $this->setAdminUser();
        $session = $this->create_session(['fullname' => 'Planned'], course_session::STATUS_PENDING, null);

        $result = $this->call((int) $session->get('id'));

        $this->assertSame('', $result['importstate']);
        $this->assertSame('', $result['importmessage']);
        $this->assertSame(0, $result['copycourseid']);
        $this->assertArrayNotHasKey('copycourseurl', $result);
        $this->assertSame(0, $result['courseid']);
    }
}
