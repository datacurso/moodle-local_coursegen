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
use local_coursegen\local\service\testable_course_import_service;
use local_coursegen\task\import_generated_course_task;

/**
 * Contract tests for the import target mode of the create_course web service (ticket #9539).
 *
 * In import mode the AI-generated course is a staging course placed in the
 * target's category, and the import is either queued as an adhoc task or run
 * inside the request, per the importexecution setting. The AI service is
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
        require_once(__DIR__ . '/fixtures/testable_course_import_service.php');

        // Any accidental real API call must fail fast instead of reaching the network.
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
        set_config('enableimportexisting', 1, 'local_coursegen');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_create_course::$mockservice = null;
        testable_create_course::$importserviceclass = null;
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
     * Give the user, at system level, the full creation set the web service gate requires
     * (editingteacher archetype plus four capabilities, ticket #9539).
     *
     * @param int $userid User id.
     * @return int The creator role id, so a test can override one of its capabilities.
     */
    private function allow_ws_gate_at_system(int $userid): int {
        $roleid = $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_creator_role();
        role_assign($roleid, $userid, \context_system::instance()->id);
        return $roleid;
    }

    /**
     * Give the user, at the target's category, every capability the import pre-check requires except one.
     *
     * @param int $userid User id.
     * @param \stdClass $target Target course record.
     * @param string $missing The capability to leave out.
     * @return void
     */
    private function allow_import_target_except(int $userid, \stdClass $target, string $missing): void {
        $categorycontext = \context_coursecat::instance($target->category);
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'importer_partial']);
        $capabilities = [
            'moodle/backup:backupcourse',
            'moodle/restore:restorecourse',
            'moodle/course:visibility',
            'moodle/backup:userinfo',
            'moodle/restore:userinfo',
        ];
        foreach (array_diff($capabilities, [$missing]) as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $categorycontext->id, true);
        }
        role_assign($roleid, $userid, $categorycontext->id);
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
        $this->assertSame('', $result['importstate']);
        $this->assertSame(0, $result['copycourseid']);
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
     * Without the user data capabilities on the target (the creation set carries backup, restore and
     * visibility but neither moodle/backup:userinfo nor moodle/restore:userinfo) the import is denied
     * before any course is materialised.
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
     * A user holding the creation set (which includes backup, restore and course:create) but not
     * moodle/course:visibility on the target must be rejected before any staging course is created:
     * the import hides the original course, so queuing a task that is bound to fail at run time
     * would waste a generated course.
     */
    public function test_import_mode_denies_user_without_visibility_capability(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $creatorroleid = $this->allow_ws_gate_at_system($user->id);
        $target = $this->getDataGenerator()->create_course();
        $targetcontext = \context_course::instance($target->id);
        assign_capability('moodle/course:visibility', CAP_PROHIBIT, $creatorroleid, $targetcontext->id, true);
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $service = $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $service->expects($this->never())->method('get_course_result');
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
            $this->fail('Import mode must require moodle/course:visibility on the target.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:visibility'), $e->a);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertSame([], $this->import_data((int) $session->get('id')));
    }

    /**
     * The capabilities that let the import copy user data, each denied on its own.
     *
     * @return array[]
     */
    public static function userinfo_capability_provider(): array {
        return [
            'backup userinfo' => ['moodle/backup:userinfo'],
            'restore userinfo' => ['moodle/restore:userinfo'],
        ];
    }

    /**
     * The copy always carries the user data of the target, so a user who may not back up or restore
     * user data is rejected before any staging course is created, never when the task runs.
     *
     * @dataProvider userinfo_capability_provider
     * @param string $capability The one required capability the user lacks.
     */
    public function test_import_mode_denies_user_without_userinfo_capability(string $capability): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->allow_ws_gate_at_system($user->id);
        $target = $this->getDataGenerator()->create_course();
        $this->allow_import_target_except($user->id, $target, $capability);
        $this->setUser($user);
        $session = $this->create_session((int) $user->id);
        $service = $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $service->expects($this->never())->method('get_course_result');
        $coursesbefore = $DB->count_records('course');

        try {
            testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
            $this->fail('Import mode must require ' . $capability . ' for the target.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($capability), $e->a);
        }

        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertSame([], $this->import_data((int) $session->get('id')));
    }

    /**
     * Values of local_coursegen/importexecution that queue the adhoc task.
     *
     * @return array[]
     */
    public static function adhoc_execution_provider(): array {
        return [
            'setting unset' => [null],
            'explicit adhoc' => ['adhoc'],
            'unknown value falls back to adhoc' => ['later'],
        ];
    }

    /**
     * Happy path: the staging course lands in the target's category whatever the AI or the user
     * asked for, one import task is queued for the acting user and the session is marked queued.
     *
     * @dataProvider adhoc_execution_provider
     * @param string|null $execution Value of local_coursegen/importexecution, null to leave it unset.
     */
    public function test_import_mode_creates_staging_in_target_category_and_queues_task(?string $execution): void {
        global $DB;

        if ($execution === null) {
            unset_config('importexecution', 'local_coursegen');
        } else {
            set_config('importexecution', $execution, 'local_coursegen');
        }
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
        $this->assertSame('queued', $result['importstate']);
        $this->assertSame(0, $result['copycourseid']);
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
     * Foreground execution: the import runs inside the request, nothing is queued, and the
     * response already carries the terminal state and the copy that replaced the target.
     */
    public function test_sync_execution_imports_in_request_and_returns_done(): void {
        global $DB;

        set_config('importexecution', 'sync', 'local_coursegen');
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course(['shortname' => 'TARGET', 'fullname' => 'Target course']);
        $session = $this->create_session((int) get_admin()->id);
        $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);

        $result = testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
        $result = \external_api::clean_returnvalue(create_course::execute_returns(), $result);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertTrue($result['importqueued']);
        $this->assertSame((int) $target->id, $result['targetcourseid']);
        $this->assertSame('done', $result['importstate']);
        $this->assertGreaterThan(0, $result['copycourseid']);
        $stagingid = $result['courseid'];
        $this->assertGreaterThan(0, $stagingid);

        // The copy took the target's names, the staging course is gone and the original is a hidden backup.
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertSame('TARGET', $copy->shortname);
        $this->assertSame('Target course', $copy->fullname);
        $this->assertFalse($DB->record_exists('course', ['id' => $stagingid]));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertMatchesRegularExpression('/^TARGET\.\d{10}$/', $original->shortname);
        $this->assertMatchesRegularExpression('/^Target course \(\d{10}\)$/', $original->fullname);
        $this->assertSame(0, (int) $original->visible);

        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_CREATED, (int) $session->get('status'));
        $this->assertSame($result['copycourseid'], (int) $session->get('courseid'));
        $import = $this->import_data((int) $session->get('id'));
        $this->assertSame('done', $import['state']);
        $this->assertSame($result['copycourseid'], (int) $import['copycourseid']);
        $this->assertSame((int) $target->id, (int) $import['targetcourseid']);
        $this->assertSame($stagingid, (int) $import['stagingcourseid']);
    }

    /**
     * Foreground execution, failed import: the service rolls back, the response reports the
     * failure with no copy, the staging course is kept and nothing is queued.
     */
    public function test_sync_execution_returns_failed_and_keeps_staging_when_import_fails(): void {
        global $DB;

        set_config('importexecution', 'sync', 'local_coursegen');
        testable_create_course::$importserviceclass = testable_course_import_service::class;
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course(['shortname' => 'TARGET', 'fullname' => 'Target course']);
        $session = $this->create_session((int) get_admin()->id);
        $this->mock_result(['fullname' => 'AI course', 'shortname' => 'aicourse']);
        $coursesbefore = $DB->count_records('course');

        $result = testable_create_course::execute((int) $session->get('id'), '', '', 0, 'import', (int) $target->id);
        $result = \external_api::clean_returnvalue(create_course::execute_returns(), $result);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(import_generated_course_task::class));
        $this->assertTrue($result['importqueued']);
        $this->assertSame((int) $target->id, $result['targetcourseid']);
        $this->assertSame('failed', $result['importstate']);
        $this->assertSame(0, $result['copycourseid']);
        $stagingid = $result['courseid'];
        $this->assertGreaterThan(0, $stagingid);

        // Only the staging course remains on top of what existed: the copy was rolled back.
        $this->assertSame($coursesbefore + 1, $DB->count_records('course'));
        $this->assertTrue($DB->record_exists('course', ['id' => $stagingid]));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('TARGET', $original->shortname);
        $this->assertSame('Target course', $original->fullname);
        $this->assertSame(1, (int) $original->visible);

        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int) $session->get('status'));
        $this->assertSame($stagingid, (int) $session->get('courseid'));
        $import = $this->import_data((int) $session->get('id'));
        $this->assertSame('failed', $import['state']);
        $this->assertSame(\RuntimeException::class, $import['errorclass']);
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
