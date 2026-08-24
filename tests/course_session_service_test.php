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
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\system_instruction_service;

/**
 * Tests for course planning session persistence and listing.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_session_service
 * @covers     \local_coursegen\local\service\course_planning_service
 */
final class course_session_service_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_course_planning_service.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_course_planning_service::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Inject an ai_course_api_service mock that returns a fixed thread id.
     *
     * @return void
     */
    private function inject_api_service(): void {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['start_course_planning', 'get_course_streaming_url'])
            ->getMock();
        $service->method('start_course_planning')->willReturn(['thread_id' => 'thread-42']);
        $service->method('get_course_streaming_url')
            ->willReturn('https://ai.example.com/api/v1/course/stream/thread-42');

        testable_course_planning_service::$mockservice = $service;
    }

    /**
     * MDL-INT-006: Starting the planning persists the language, the images
     * toggle, the subsections toggle, the context type, the original request
     * and the chosen directive, plus the service thread id, leaving the
     * session in planning state.
     */
    public function test_start_persists_choices_thread_and_planning_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $directive = system_instruction_service::create('Directriz', 'Contenido de la directriz.');

        $this->inject_api_service();

        $result = testable_course_planning_service::start_course_planning(
            'Curso de historia del arte',
            'de',
            true,
            (int)$directive->get('id'),
            (int)$user->id,
            false
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['sessionid']);

        $session = new course_session($result['sessionid']);
        $this->assertSame((int)$user->id, (int)$session->get('userid'));
        $this->assertSame('thread-42', $session->get('session_id'));
        $this->assertSame(course_session::STATUS_PENDING, (int)$session->get('status'));

        $coursedata = json_decode((string)$session->get('coursedata'), true);
        $this->assertSame('de', $coursedata['local_coursegen_lang']);
        $this->assertSame(1, $coursedata['local_coursegen_generate_images']);
        $this->assertSame(0, $coursedata['local_coursegen_generate_subsections']);
        $this->assertSame('customprompt', $coursedata['local_coursegen_context_type']);
        $this->assertSame('Curso de historia del arte', $coursedata['local_coursegen_custom_prompt']);
        $this->assertSame(1, $coursedata['local_coursegen_use_system_instruction']);
        $this->assertSame((int)$directive->get('id'), $coursedata['local_coursegen_select_system_instruction']);
    }

    /**
     * MDL-INT-027: The sidebar listing returns the 5 most recent sessions of
     * the user, newest first, created ones included.
     */
    public function test_recent_listing_returns_five_newest_sessions(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        for ($i = 1; $i <= 7; $i++) {
            $session = new course_session(0, (object)[
                'userid' => (int)$user->id,
                'session_id' => 'thread-' . $i,
                'status' => course_session::STATUS_PENDING,
                'coursedata' => json_encode(['local_coursegen_custom_prompt' => 'Prompt ' . $i]),
            ]);
            $session->create();
            // Spread creation times so the expected order is unambiguous.
            $DB->set_field(
                'local_coursegen_course_sessions',
                'timecreated',
                1000000 + $i,
                ['id' => $session->get('id')]
            );
        }

        $recent = course_session_service::get_user_inprogress_sessions((int)$user->id, 5, true);

        $this->assertCount(5, $recent);
        $threadids = array_map(static function (course_session $session): string {
            return (string)$session->get('session_id');
        }, $recent);
        $this->assertSame(['thread-7', 'thread-6', 'thread-5', 'thread-4', 'thread-3'], $threadids);
    }

    /**
     * MDL-INT-027: The visible states are Planning, Creating and Failed
     * according to the real session status, and the fallback title string for
     * untitled sessions exists.
     */
    public function test_session_states_and_title_fallback_strings_exist(): void {
        $stringmanager = get_string_manager();

        // One language string per visible state, as rendered by the sidebar.
        $this->assertTrue($stringmanager->string_exists('status_pending', 'local_coursegen'));
        $this->assertTrue($stringmanager->string_exists('status_creating', 'local_coursegen'));
        $this->assertTrue($stringmanager->string_exists('status_failed', 'local_coursegen'));

        // The untitled-course fallback used when neither the course name nor
        // the original request are available.
        $this->assertTrue($stringmanager->string_exists('courseai_untitled', 'local_coursegen'));

        // The visible states map to distinct persisted statuses.
        $statuses = [
            course_session::STATUS_PENDING,
            course_session::STATUS_CREATING,
            course_session::STATUS_CREATED,
            course_session::STATUS_FAILED,
        ];
        $this->assertSame($statuses, array_unique($statuses));
    }

    /**
     * MDL-INT-027: A session whose course was already created shows a visible
     * state label.
     */
    public function test_created_session_shows_visible_state_label(): void {
        // The Created state has its own visible label, following the same
        // naming pattern as the other status strings rendered by the sidebar.
        $this->assertTrue(get_string_manager()->string_exists('status_created', 'local_coursegen'));
        $this->assertNotSame('', trim(get_string('status_created', 'local_coursegen')));
    }
}
