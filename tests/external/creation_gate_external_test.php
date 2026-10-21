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
use context_user;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\testable_courseai_syllabus_upload;
use local_coursegen\testable_create_course;
use local_coursegen\testable_get_course_session_state;
use local_coursegen\testable_get_course_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * The web services of the AI course creation flow apply the creation permission gate (ticket #9539).
 *
 * A user who could open the page under the old two-capability check but does
 * not hold the full creation set (editingteacher archetype plus four) is
 * refused by every service before any work is done; a user holding the full
 * set reaches the existing behaviour, mocked where it would hit the network.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_course_planning
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\get_course_session_state
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 * @covers     \local_coursegen\external\courseai_filepicker_init
 * @covers     \local_coursegen\external\create_course
 * @runTestsInSeparateProcesses
 */
final class creation_gate_external_test extends \advanced_testcase {
    /**
     * Load the testable subclasses in the isolated process (they pull in lib/externallib.php).
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_get_course_settings.php');
        require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_get_course_session_state.php');
        require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_courseai_syllabus_upload.php');
        require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_create_course.php');

        // Any accidental real API call must fail fast instead of reaching the network.
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_get_course_settings::$mockservice = null;
        testable_get_course_session_state::$mockservice = null;
        testable_courseai_syllabus_upload::$mockservice = null;
        testable_create_course::$mockservice = null;
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Create a planning session owned by the given user.
     *
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function create_session(int $userid): course_session {
        return course_session_service::create_from_form_data(
            (object) ['fullname' => 'Planned course'],
            $userid,
            'thread-gate-1'
        );
    }

    /**
     * A user who passed the old gate: stock coursecreator at system level plus the two page capabilities.
     *
     * @return \stdClass
     */
    private function create_coursecreator_user(): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();
        $user = $generator->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'coursecreator'], MUST_EXIST), $user->id, $systemcontext->id);
        $roleid = $generator->create_role(['shortname' => 'oldgate']);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $user->id, $systemcontext->id);
        return $user;
    }

    /**
     * A user holding the full creation set at system level.
     *
     * @return array{0: \stdClass, 1: int} The user and the role id.
     */
    private function create_full_user(): array {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->get_plugin_generator('local_coursegen')->create_course_creator_role();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return [$user, $roleid];
    }

    /**
     * Put a small PDF into a fresh draft area of the current user.
     *
     * @return int Draft item id.
     */
    private function create_draft_pdf(): int {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object) [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 syllabus body');
        return $draftitemid;
    }

    /**
     * Inject API service doubles that fail the test if any of them is reached.
     *
     * @return void
     */
    private function inject_unreachable_services(): void {
        $settings = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_result'])->getMock();
        $settings->expects($this->never())->method('get_course_result');
        testable_get_course_settings::$mockservice = $settings;
        testable_create_course::$mockservice = $settings;

        $state = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_state', 'get_course_streaming_url'])->getMock();
        $state->expects($this->never())->method('get_course_state');
        testable_get_course_session_state::$mockservice = $state;

        $upload = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['upload_syllabus'])->getMock();
        $upload->expects($this->never())->method('upload_syllabus');
        testable_courseai_syllabus_upload::$mockservice = $upload;
    }

    /**
     * Inject API service doubles returning a usable answer.
     *
     * @return void
     */
    private function inject_answering_services(): void {
        $settings = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_result'])->getMock();
        $settings->method('get_course_result')->willReturn([
            'result' => ['course_configuration' => ['fullname' => 'AI course', 'shortname' => 'aicourse']],
        ]);
        testable_get_course_settings::$mockservice = $settings;

        $state = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_state', 'get_course_streaming_url'])->getMock();
        $state->method('get_course_state')->willReturn(['status' => 'completed']);
        $state->method('get_course_streaming_url')->willReturn('https://example.com/stream/thread-gate-1');
        testable_get_course_session_state::$mockservice = $state;

        $upload = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['upload_syllabus'])->getMock();
        $upload->method('upload_syllabus')->willReturn(['ok' => true]);
        testable_courseai_syllabus_upload::$mockservice = $upload;
    }

    /**
     * Inject an API client double answering the planning endpoints.
     *
     * start_course_planning and course_planning_feedback build their API service
     * internally, so the double goes in at the client factory. This keeps the
     * test independent of aiprovider_datacurso: the real client is never built.
     *
     * @return ai_course_api The injected double, for further expectations.
     */
    private function inject_planning_client(): ai_course_api {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()->onlyMethods(['request', 'get_base_url'])->getMock();
        $client->method('request')->willReturn(['thread_id' => 'thread-gate-2']);
        $client->method('get_base_url')->willReturn('https://example.com');
        api_client_factory::set_test_client($client);
        return $client;
    }

    /**
     * Call one service of the flow as the current user.
     *
     * @param string $service Service name.
     * @param int $sessionid Session record id.
     * @return mixed The service result.
     */
    private function call(string $service, int $sessionid) {
        switch ($service) {
            case 'start_course_planning':
                return start_course_planning::execute('Plan a course about tests', 'en');
            case 'course_planning_feedback':
                return course_planning_feedback::execute($sessionid, ['action' => 'accept', 'target_ids' => []]);
            case 'get_course_settings':
                return testable_get_course_settings::execute($sessionid);
            case 'get_course_session_state':
                return testable_get_course_session_state::execute($sessionid);
            case 'courseai_syllabus_upload':
                return testable_courseai_syllabus_upload::execute($sessionid, $this->create_draft_pdf());
            case 'courseai_filepicker_init':
                return courseai_filepicker_init::execute();
        }
        throw new \coding_exception('Unknown service ' . $service);
    }

    /**
     * The six services of the flow.
     *
     * @return array[]
     */
    public static function service_provider(): array {
        $services = [
            'start_course_planning',
            'course_planning_feedback',
            'get_course_settings',
            'get_course_session_state',
            'courseai_syllabus_upload',
            'courseai_filepicker_init',
        ];
        $cases = [];
        foreach ($services as $service) {
            $cases[$service] = [$service];
        }
        return $cases;
    }

    /**
     * A coursecreator holding the two page capabilities is refused by every service of the flow.
     *
     * @dataProvider service_provider
     * @param string $service Service name.
     */
    public function test_services_refuse_a_user_without_the_full_creation_set(string $service): void {
        $user = $this->create_coursecreator_user();
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $this->inject_unreachable_services();

        try {
            $this->call($service, (int) $session->get('id'));
            $this->fail($service . ' must throw required_capability_exception without the full creation set.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
        $this->resetDebugging();
    }

    /**
     * A user holding the full creation set passes the gate of every service and reaches its behaviour.
     *
     * @dataProvider service_provider
     * @param string $service Service name.
     */
    public function test_services_pass_the_gate_with_the_full_creation_set(string $service): void {
        [$user] = $this->create_full_user();
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $this->inject_answering_services();

        switch ($service) {
            case 'start_course_planning':
                $this->inject_planning_client()->expects($this->once())->method('request')
                    ->with('POST', '/course/init', $this->anything());
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertTrue($result['success']);
                $this->assertSame('thread-gate-2', $result['threadid']);
                break;
            case 'course_planning_feedback':
                $this->inject_planning_client()->expects($this->once())->method('request')
                    ->with('POST', '/course/feedback', $this->anything());
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertTrue($result['success']);
                break;
            case 'get_course_settings':
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertSame('AI course', $result['fullname']);
                break;
            case 'get_course_session_state':
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertSame('https://example.com/stream/thread-gate-1', $result['streamingurl']);
                break;
            case 'courseai_syllabus_upload':
                testable_courseai_syllabus_upload::$mockservice->expects($this->once())->method('upload_syllabus');
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertTrue($result['success']);
                break;
            case 'courseai_filepicker_init':
                $result = $this->call($service, (int) $session->get('id'));
                $this->assertNotEmpty($result['clientid']);
                break;
        }
    }

    /**
     * create_course refuses a coursecreator holding the two page capabilities before fetching the result.
     */
    public function test_create_course_refuses_a_user_without_the_full_creation_set(): void {
        global $DB;
        $user = $this->create_coursecreator_user();
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $this->inject_unreachable_services();
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'));
            $this->fail('create_course must throw required_capability_exception without the full creation set.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }

    /**
     * A user with the full set everywhere except one capability prohibited on the chosen category is
     * refused, and the exception names that capability.
     */
    public function test_create_course_names_the_capability_prohibited_on_the_chosen_category(): void {
        global $DB;
        [$user, $roleid] = $this->create_full_user();
        $category = $this->getDataGenerator()->create_category();
        assign_capability('mod/quiz:addinstance', CAP_PROHIBIT, $roleid, \context_coursecat::instance($category->id)->id, true);
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $settings = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_result'])->getMock();
        $settings->method('get_course_result')->willReturn([
            'result' => ['course_configuration' => [
                'fullname' => 'AI course', 'shortname' => 'aicourse', 'category' => (int) $category->id,
            ]],
        ]);
        testable_create_course::$mockservice = $settings;
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'));
            $this->fail('create_course must refuse a category where one capability is prohibited.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('mod/quiz:addinstance'), $e->a);
        }
        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }

    /**
     * A user with the full set creates the course in the chosen category.
     */
    public function test_create_course_passes_the_gate_with_the_full_creation_set(): void {
        global $DB;
        [$user] = $this->create_full_user();
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $settings = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()->onlyMethods(['get_course_result'])->getMock();
        $settings->method('get_course_result')->willReturn([
            'result' => ['course_configuration' => [
                'fullname' => 'AI course', 'shortname' => 'aicourse', 'category' => (int) $category->id,
            ]],
        ]);
        testable_create_course::$mockservice = $settings;

        $result = testable_create_course::execute((int) $session->get('id'));

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertEquals($category->id, $DB->get_field('course', 'category', ['id' => $result['courseid']]));
    }
}
