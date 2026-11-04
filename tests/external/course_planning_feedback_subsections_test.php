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

namespace local_coursegen\external;

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/aiprovider_datacurso_stub.php');

/**
 * Tests that accepting subsections from the decision card is kept in the session.
 *
 * The external classes load lib/externallib.php, which requires each test to
 * run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\course_planning_feedback
 *
 * @runTestsInSeparateProcesses
 */
final class course_planning_feedback_subsections_test extends \advanced_testcase {
    /**
     * Drop the injected client between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Enabling subsections from the decision card switches the session to subsections.
     */
    public function test_enable_subsections_is_recorded_in_the_session(): void {
        $session = $this->create_session();

        course_planning_feedback::execute((int) $session->get('id'), ['action' => 'enable_subsections']);
        $this->resetDebugging();

        $this->assertSame(1, $this->stored_subsections_flag($session));
    }

    /**
     * Any other action leaves the subsections choice as it was.
     */
    public function test_other_actions_keep_the_subsections_choice(): void {
        $session = $this->create_session();

        course_planning_feedback::execute((int) $session->get('id'), ['action' => 'feedback', 'instruction' => 'Shorter']);
        $this->resetDebugging();

        $this->assertSame(0, $this->stored_subsections_flag($session));
    }

    /**
     * Create a pending planning session without subsections, and a client that accepts feedback.
     *
     * @return course_session
     */
    private function create_session(): course_session {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => 'thread-subsections',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode([
                'local_coursegen_context_type' => 'customprompt',
                'local_coursegen_generate_subsections' => 0,
            ]),
        ]);
        $session->create();

        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $client->method('request')->willReturn(['ok' => true]);
        api_client_factory::set_test_client($client);

        return $session;
    }

    /**
     * The subsections flag stored for a session.
     *
     * @param course_session $session Planning session.
     * @return int
     */
    private function stored_subsections_flag(course_session $session): int {
        $stored = new course_session((int) $session->get('id'));
        $coursedata = json_decode((string) $stored->get('coursedata'), true);
        return (int) ($coursedata['local_coursegen_generate_subsections'] ?? -1);
    }
}
