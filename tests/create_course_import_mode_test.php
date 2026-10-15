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

use local_coursegen\external\create_course;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\task\import_generated_course_task;

/**
 * Contract tests for the import target mode of the create_course web service (ticket #9539).
 *
 * In import mode the AI-generated course is a staging course placed in the
 * target's category, and an adhoc import task is queued. The AI service is
 * mocked through the testable subclass, so no network request is performed.
 * The fixture loads lib/externallib.php, so each test runs in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_course
 *
 * @runTestsInSeparateProcesses
 */
final class create_course_import_mode_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process and enable the feature.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        require_once(__DIR__ . '/fixtures/testable_create_course.php');

        // Any accidental real API call must fail fast instead of reaching the network.
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
        set_config('enableimportexisting', 1, 'local_coursegen');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_create_course::$mockservice = null;
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
            'thread-import-ws-1'
        );
    }

    /**
     * Inject a mock API service whose course result carries the given configuration.
     *
     * @param array $configuration course_configuration values returned by the AI.
     * @param array $extra Extra keys merged into the result (sections_info, ...).
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function mock_result(array $configuration, array $extra = []): \PHPUnit\Framework\MockObject\MockObject {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_course_result'])
            ->getMock();
        $service->method('get_course_result')->willReturn([
            'result' => ['course_configuration' => $configuration] + $extra,
        ]);
        testable_create_course::$mockservice = $service;
        return $service;
    }

    /**
     * Give the user the two system-level capabilities the web service gate requires.
     *
     * @param int $userid User id.
     * @return void
     */
    private function allow_ws_gate_at_system(int $userid): void {
        $systemcontext = \context_system::instance();
        $roleid = create_role('AI course creator', 'aicoursecreator', '');
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $userid, $systemcontext->id);
    }

    /**
     * Decode the import bookkeeping stored in the session JSON.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    private function import_data(int $sessionid): array {
        $session = new course_session($sessionid);
        $coursedata = json_decode((string) $session->get('coursedata'), true);
        return $coursedata['import'] ?? [];
    }

    /**
     * Target modes that keep today's behaviour.
     *
     * @return array[]
     */
    public static function new_mode_provider(): array {
        return [
            'targetmode omitted' => [null],
            'targetmode new' => ['new'],
        ];
    }

    /**
     * The 'new' mode (explicit or omitted) keeps the result shape, reports no import and queues nothing.
     *
     * @dataProvider new_mode_provider
     * @param string|null $targetmode Target mode argument, null to omit it.
     */
    public function test_new_mode_keeps_result_shape_and_queues_nothing(?string $targetmode): void {
        global $DB;

        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $session = $this->create_session((int) get_admin()->id);
        $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse', 'category' => (int) $category->id]);

        if ($targetmode === null) {
            $result = testable_create_course::execute((int) $session->get('id'));
        } else {
            $result = testable_create_course::execute((int) $session->get('id'), '', '', 0, $targetmode);
        }
        $result = \external_api::clean_returnvalue(create_course::execute_returns(), $result);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['importqueued']);
        $this->assertSame(0, $result['targetcourseid']);
        $this->assertSame('AI course', $result['fullname']);
        $this->assertSame('aicourse', $result['shortname']);
        $this->assertEquals($category->id, $DB->get_field('course', 'category', ['id' => $result['courseid']]));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertSame([], $this->import_data((int) $session->get('id')));
    }

    /**
     * Import mode is refused while the site setting is off: nothing is created or queued.
     */
    public function test_import_mode_is_rejected_when_setting_is_disabled(): void {
        global $DB;

        set_config('enableimportexisting', 0, 'local_coursegen');
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course();
        $session = $this->create_session((int) get_admin()->id);
        $service = $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $service->expects($this->never())->method('get_course_result');
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
            $this->fail('Import mode must be refused while enableimportexisting is off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_import_existing_disabled', $e->errorcode);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
    }

    /**
     * Invalid target course ids.
     *
     * @return array[]
     */
    public static function invalid_target_provider(): array {
        return [
            'zero' => ['zero', 'invalidparameter'],
            'nonexistent' => ['nonexistent', 'error_import_target_invalid'],
            'site course' => ['site', 'error_import_target_invalid'],
        ];
    }

    /**
     * Import mode refuses a missing, unknown or site-course target: nothing is created or queued.
     *
     * @dataProvider invalid_target_provider
     * @param string $kind Which invalid id to send.
     * @param string $expectederrorcode Expected moodle_exception errorcode.
     */
    public function test_import_mode_rejects_invalid_target(string $kind, string $expectederrorcode): void {
        global $DB;

        $this->setAdminUser();
        $session = $this->create_session((int) get_admin()->id);
        $service = $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $service->expects($this->never())->method('get_course_result');

        $targetids = ['zero' => 0, 'nonexistent' => 999999, 'site' => SITEID];
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', $targetids[$kind]);
            $this->fail('Import mode must refuse target "' . $kind . '".');
        } catch (\moodle_exception $e) {
            $this->assertSame($expectederrorcode, $e->errorcode);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
    }

    /**
     * Without backup/restore on the target the import is denied before any course is materialised.
     */
    public function test_import_mode_denies_user_without_target_capabilities(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->allow_ws_gate_at_system($user->id);
        $this->setUser($user);
        $target = $this->getDataGenerator()->create_course();
        $session = $this->create_session((int) $user->id);
        $service = $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $service->expects($this->never())->method('get_course_result');
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
            $this->fail('Import mode must require the import capabilities on the target.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertSame([], $this->import_data((int) $session->get('id')));
    }

    /**
     * Happy path: the staging course lands in the target's category whatever the AI or the user
     * asked for, one import task is queued for the acting user and the session is marked queued.
     */
    public function test_import_mode_creates_staging_in_target_category_and_queues_task(): void {
        global $DB;

        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        $generator = $this->getDataGenerator();
        $targetcategory = $generator->create_category(['name' => 'Target category']);
        $othercategory = $generator->create_category(['name' => 'Other category']);
        $target = $generator->create_course(['category' => $targetcategory->id, 'shortname' => 'TARGET']);
        $session = $this->create_session($adminid);
        $this->mock_result([
            'fullname' => 'AI course',
            'shortname' => 'TARGET',
            'category' => (int) $othercategory->id,
        ]);

        $result = testable_create_course::execute(
            (int) $session->get('id'),
            '',
            '',
            (int) $othercategory->id,
            'import',
            (int) $target->id
        );
        $result = \external_api::clean_returnvalue(create_course::execute_returns(), $result);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertTrue($result['importqueued']);
        $this->assertSame((int) $target->id, $result['targetcourseid']);
        $stagingid = $result['courseid'];
        $this->assertGreaterThan(0, $stagingid);
        $this->assertNotSame((int) $target->id, $stagingid);

        $staging = $DB->get_record('course', ['id' => $stagingid], '*', MUST_EXIST);
        $this->assertEquals($targetcategory->id, $staging->category);
        $this->assertNotSame('TARGET', $staging->shortname, 'The staging shortname must not collide with the target.');
        $this->assertSame('TARGET', $DB->get_field('course', 'shortname', ['id' => $target->id]));

        $tasks = \core\task\manager::get_adhoc_tasks(import_generated_course_task::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame($adminid, (int) $task->get_userid());
        $data = $task->get_custom_data();
        $this->assertSame((int) $session->get('id'), (int) $data->sessionid);
        $this->assertSame($stagingid, (int) $data->stagingcourseid);
        $this->assertSame((int) $target->id, (int) $data->targetcourseid);

        $import = $this->import_data((int) $session->get('id'));
        $this->assertSame('queued', $import['state']);
        $this->assertSame((int) $target->id, (int) $import['targetcourseid']);
        $this->assertSame($stagingid, (int) $import['stagingcourseid']);
    }

    /**
     * When the generation itself fails the failure is returned as-is and no import is queued.
     */
    public function test_import_mode_returns_failure_without_queueing_when_generation_fails(): void {
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course();
        $session = $this->create_session((int) get_admin()->id);
        // A non-array sections_info makes the service's typed section processing throw.
        $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse'], ['sections_info' => 'broken']);

        $result = testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
        $this->assertDebuggingCalled();
        $result = \external_api::clean_returnvalue(create_course::execute_returns(), $result);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['importqueued']);
        $this->assertSame(0, $result['courseid']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertSame([], $this->import_data((int) $session->get('id')));
    }
}
