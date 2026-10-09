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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\external\activity_feedback;
use local_coursegen\external\activity_file_upload;
use local_coursegen\external\course_planning_feedback;
use local_coursegen\external\start_course_planning;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * Error disclosure tests: technical exception details must never reach the
 * client response; only localized messages do, while the technical text is
 * kept in developer debugging output.
 *
 * The AI service is mocked, so no network request is ever performed. The
 * testable subclass fixture and the external classes load lib/externallib.php,
 * which requires each test to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_feedback
 * @covers     \local_coursegen\external\activity_file_upload
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\create_mod
 * @covers     \local_coursegen\external\start_course_planning
 *
 * @runTestsInSeparateProcesses
 */
final class error_message_disclosure_test extends \advanced_testcase {
    /** @var string Technical marker that must never surface in a client response. */
    private const TECHNICALDETAIL = 'TECH-SECRET curl error 500 at https://internal-api.invalid/course/init';

    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_create_mod.php');

        // Any accidental real API call must fail fast instead of reaching the network.
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_create_mod::$mockservice = null;
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Assert the technical detail was routed to developer debugging and clear it.
     *
     * @return void
     */
    private function assert_technical_detail_debugged(): void {
        $messages = array_map(static function ($debug) {
            return (string) $debug->message;
        }, $this->getDebuggingMessages());
        $this->resetDebugging();

        $matches = array_filter($messages, static function (string $message): bool {
            return strpos($message, self::TECHNICALDETAIL) !== false;
        });
        $this->assertNotEmpty($matches, 'The technical detail must be kept in debugging output.');
    }

    /**
     * Inject an API client double whose calls always fail with the technical marker.
     *
     * @param string $method Client method to break ('request' or 'upload_file').
     * @return void
     */
    private function inject_failing_client(string $method): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods([$method])
            ->getMock();
        $client->method($method)->willThrowException(new \coding_exception(self::TECHNICALDETAIL));
        api_client_factory::set_test_client($client);
    }

    /**
     * Assert a localized exception carries no technical detail in any of the
     * fields a language pack can render.
     *
     * The placeholder argument matters even when the English string ignores it:
     * translated packs interpolate {$a}, so a technical detail stored there is
     * shown verbatim to any user running the site in another language.
     *
     * @param \moodle_exception $e Exception raised by the endpoint.
     * @param string $errorcode Expected error code.
     * @return void
     */
    private function assert_localized_exception_without_detail(\moodle_exception $e, string $errorcode): void {
        $this->assertSame($errorcode, $e->errorcode);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, (string) $e->a);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, (string) $e->debuginfo);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, $e->getMessage());
    }

    /**
     * A service exception in activity_feedback surfaces a localized message and
     * the technical detail stays out of every user-facing field.
     */
    public function test_activity_feedback_error_returns_localized_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, (int) $USER->id, 'job-feedback', 0, null, null, 1, null, 'completed');

        $this->inject_failing_client('request');

        try {
            activity_feedback::execute($course->id, 'job-feedback', 'accept');
            $this->fail('activity_feedback must rethrow a localized exception.');
        } catch (\moodle_exception $e) {
            $this->assert_localized_exception_without_detail($e, 'error_sending_feedback');
        }

        $this->assert_technical_detail_debugged();
    }

    /**
     * A service exception in course_planning_feedback surfaces a localized
     * message and the technical detail stays out of every user-facing field.
     */
    public function test_course_planning_feedback_error_returns_localized_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $session = course_session_service::create_from_form_data(
            (object) ['fullname' => 'AI course'],
            (int) $USER->id,
            'thread-feedback'
        );

        $this->inject_failing_client('request');

        try {
            course_planning_feedback::execute((int) $session->get('id'), ['action' => 'accept']);
            $this->fail('course_planning_feedback must rethrow a localized exception.');
        } catch (\moodle_exception $e) {
            $this->assert_localized_exception_without_detail($e, 'error_sending_feedback');
        }

        $this->assert_technical_detail_debugged();
    }

    /**
     * A service exception in activity_file_upload surfaces a localized message
     * and the technical detail stays out of every user-facing field.
     */
    public function test_activity_file_upload_error_returns_localized_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, (int) $USER->id, 'job-upload', 0, null, null, 1, null, 'completed');

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'notes.txt',
        ], 'Course notes.');

        $this->inject_failing_client('upload_file');

        try {
            activity_file_upload::execute($course->id, 'job-upload', $draftitemid);
            $this->fail('activity_file_upload must rethrow a localized exception.');
        } catch (\moodle_exception $e) {
            $this->assert_localized_exception_without_detail($e, 'error_sending_activity_file');
        }

        $this->assert_technical_detail_debugged();
    }

    /**
     * No language pack may interpolate {$a} in the error strings that report an
     * upstream AI failure: a placeholder there would render whatever technical
     * detail a future caller passes as the fourth moodle_exception argument.
     *
     * The packs are read from disk so a newly added translation is covered too.
     */
    public function test_upstream_error_strings_have_no_placeholder_in_any_language(): void {
        $this->resetAfterTest();

        $keys = [
            'error_sending_activity_file',
            'error_sending_feedback',
            'error_upload_failed_system_instruction',
        ];

        $files = glob(__DIR__ . '/../lang/*/local_coursegen.php');
        $this->assertNotEmpty($files, 'No language pack was found under lang/.');

        $checked = 0;
        foreach ($files as $file) {
            $pack = basename(dirname($file));
            $strings = self::load_language_pack($file);
            foreach ($keys as $key) {
                if (!array_key_exists($key, $strings)) {
                    continue;
                }
                $checked++;
                $this->assertStringNotContainsString(
                    '{$a}',
                    $strings[$key],
                    "lang/{$pack}: the string '{$key}' interpolates a placeholder and would disclose technical details."
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'None of the audited strings was found in any language pack.');
    }

    /**
     * Read the string array of a language pack file without polluting the test scope.
     *
     * @param string $file Absolute path to a lang/<code>/local_coursegen.php file.
     * @return array Strings defined by the pack.
     */
    private static function load_language_pack(string $file): array {
        $string = [];
        include($file);

        return $string;
    }

    /**
     * A service exception in create_mod surfaces a localized message and the
     * technical detail stays out of the response.
     */
    public function test_create_mod_service_error_returns_localized_message(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        module_job_service::create_job($course->id, $teacher->id, 'job-fail', 0, null, null, 1, null, 'completed');

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_activity_result'])
            ->getMock();
        $service->method('get_activity_result')->willThrowException(new \Exception(self::TECHNICALDETAIL));
        testable_create_mod::$mockservice = $service;

        $result = testable_create_mod::execute($course->id, 1, 'job-fail');

        $this->assertFalse($result['ok']);
        $this->assertSame(get_string('error_generating_resource', 'local_coursegen'), $result['message']);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, json_encode($result));
        $this->assert_technical_detail_debugged();
    }

    /**
     * A permission error in create_mod keeps its localized permission message,
     * so the teacher still learns why the request was rejected.
     */
    public function test_create_mod_permission_error_keeps_permission_message(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        module_job_service::create_job($course->id, $student->id, 'job-denied', 0, null, null, 1, null, 'completed');

        $result = testable_create_mod::execute($course->id, 1, 'job-denied');
        $this->resetDebugging();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            get_string('nopermissions', 'error', get_capability_string('moodle/course:manageactivities')),
            $result['message']
        );
    }

    /**
     * A service exception in start_course_planning surfaces a localized message
     * and the technical detail stays out of the response.
     */
    public function test_start_course_planning_error_returns_localized_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $client->method('request')->willThrowException(new \Exception(self::TECHNICALDETAIL));
        api_client_factory::set_test_client($client);

        $result = start_course_planning::execute('Create a short course about volcanoes', 'en');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['sessionid']);
        $this->assertSame(get_string('error_starting_course_planning', 'local_coursegen'), $result['message']);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, json_encode($result));
        $this->assert_technical_detail_debugged();
    }

    /**
     * Strings referenced by the syllabus flow and the planning UI must exist
     * in the English pack: string_exists() is false for keys living only in
     * translations, and an English user would see [[key]] placeholders.
     */
    public function test_referenced_language_strings_exist_in_english(): void {
        $this->resetAfterTest();

        $manager = get_string_manager();
        $keys = [
            'courseai_prv_sub_init',
            'courseai_syllabus_upload_success',
            'error_file_save_failed',
            'error_invalid_session',
            'error_no_file_uploaded',
            'error_not_your_session',
            'error_sending_activity_file',
            'error_sending_feedback',
            'error_upload_failed_system_instruction',
        ];
        foreach ($keys as $key) {
            $this->assertTrue(
                $manager->string_exists($key, 'local_coursegen'),
                "The string '{$key}' is referenced by code but missing from lang/en."
            );
        }
    }
}
