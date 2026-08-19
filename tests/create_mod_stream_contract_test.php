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
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\module_job;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\create_mod_service;

/**
 * Contract tests for the individual activity generation request and result.
 *
 * The payload the plugin hands to the AI service is captured through a mocked
 * ai_course_api_service, so no network request is ever performed.
 *
 * Note about the request defaults: site_id, site_url, userid and timezone are
 * composed by the provider layer (datacurso_api_base::send_request()) right
 * before the HTTP call, so they are not observable from the plugin without a
 * network seam in the provider. The regression for the missing site_url
 * (a real validation failure in integration) therefore lives at the provider
 * level; at the plugin level this file asserts everything execute() hands to
 * the service: instructions, lang, with_images, the image_policy that travels
 * with it and the optional h5p_core_api.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_mod_stream
 *
 * @runTestsInSeparateProcesses
 */
final class create_mod_stream_contract_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_create_mod_stream.php');
        require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_create_mod_stream::$mockservice = null;
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Make the given course the current one.
     *
     * The module edit form resolves section info through the global $COURSE.
     * In a web request require_login() binds the page (and $COURSE) to the
     * course; without it, the theme initialisation triggered by the form
     * falls back to the site course and the target section cannot resolve.
     *
     * @param \stdClass $course Course record.
     * @return void
     */
    private function set_current_course(\stdClass $course): void {
        global $PAGE;
        $PAGE->set_course($course);
    }

    /**
     * Inject an ai_course_api_service mock that captures the start_activity payload.
     *
     * @param array|null $captured Reference that receives the payload handed to start_activity().
     * @return void
     */
    private function inject_api_service(?array &$captured = null): void {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['start_activity', 'get_mod_streaming_url_for_job'])
            ->getMock();

        $service->method('start_activity')->willReturnCallback(
            function (array $payload) use (&$captured): array {
                $captured = $payload;
                return ['thread_id' => 'job-1', 'status' => 'queued', 'message' => 'Job started'];
            }
        );
        $service->method('get_mod_streaming_url_for_job')
            ->willReturn('https://ai.example.com/api/v1/activity/stream/job-1');

        testable_create_mod_stream::$mockservice = $service;
    }

    /**
     * MDL-CTR-001: The individual creation request includes the data the service
     * requires at the plugin level: instructions, language and the images option.
     * The H5P framework version travels as an optional field.
     */
    public function test_start_request_contains_required_contract_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $captured = null;
        $this->inject_api_service($captured);

        // A configured (non-disabled) admin image mode must travel with the request.
        set_config('generationmode', \local_coursegen\local\image_generation\activities::MODE_MANUAL, 'local_coursegen');

        $result = testable_create_mod_stream::execute($course->id, 1, 'Create an H5P quiz about volcanoes', 1, null, 'es');
        // One pre-existing developer notice: execute_parameters() declares
        // top-level VALUE_OPTIONAL values instead of VALUE_DEFAULT.
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok'], 'Start must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame('job-1', $result['job_id']);
        $this->assertSame('https://ai.example.com/api/v1/activity/stream/job-1', $result['streamingurl']);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('instructions', $captured);
        $this->assertArrayHasKey('lang', $captured);
        $this->assertArrayHasKey('with_images', $captured);
        $this->assertSame('Create an H5P quiz about volcanoes', $captured['instructions']);
        $this->assertSame('es', $captured['lang']);
        $this->assertTrue($captured['with_images']);

        // When images are requested and the admin policy is configured, the
        // individual flow sends the same image generation policy as the course
        // flow, so the service applies identical image rules to both.
        $this->assertArrayHasKey('image_policy', $captured);
        $this->assertIsArray($captured['image_policy']);
        $this->assertArrayHasKey('mode', $captured['image_policy']);
        $this->assertArrayHasKey('activities', $captured['image_policy']);

        // The H5P framework version is optional: when the site can resolve it,
        // it must travel in the request (see MDL-INT-010 for the exact format).
        if (isset($captured['h5p_core_api'])) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $captured['h5p_core_api']);
        }

        // The job was persisted for this user and course.
        $this->assertSame(1, module_job::count_records(['job_id' => 'job-1', 'courseid' => $course->id]));
    }

    /**
     * MDL-CTR-001: An unconfigured (disabled-by-default) admin image mode must
     * NOT travel with the request: sending mode=disabled while the teacher
     * enabled images made the service suppress the activity description image
     * (regression observed on sites without generationmode configured).
     */
    public function test_disabled_image_mode_is_not_sent_with_images_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $captured = null;
        $this->inject_api_service($captured);

        // The generationmode setting is deliberately NOT configured: defaults to disabled.
        $result = testable_create_mod_stream::execute($course->id, 1, 'Create an H5P accordion about rocks', 1, null, 'en');
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok'], 'Start must succeed: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);
        $this->assertTrue($captured['with_images']);
        $this->assertArrayNotHasKey(
            'image_policy',
            $captured,
            'A disabled-by-default policy must not override the teacher image toggle.'
        );
    }

    /**
     * MDL-CTR-001: A missing mandatory field must surface the service validation
     * detail to the teacher in an understandable way.
     *
     * The provider now surfaces the 4xx body detail through the
     * httperror_detail exception (see aiprovider_datacurso
     * datacurso_api_base::summarize_error_response() and its unit tests), and
     * create_mod_stream::execute() returns any exception message as
     * ok=false + message, so the detail reaches the teacher.
     */
    public function test_service_validation_error_reaches_teacher_clearly(): void {
        $this->markTestSkipped(
            'Requires HTTP-layer integration: the 4xx branch depends on the real HTTP status '
            . 'code, which the PHPUnit curl mock cannot simulate (it always reports 200). The '
            . 'detail extraction is unit tested at the provider level in '
            . 'aiprovider_datacurso\httpclient\datacurso_api_error_detail_test.'
        );
    }

    /**
     * MDL-INT-010: The site H5P framework version is composed as major.minor and
     * accompanies the individual activity creation request.
     */
    public function test_h5p_core_api_version_composed_from_site_framework(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $captured = null;
        $this->inject_api_service($captured);

        // Resolve the expected version the same way production code does.
        (new \core_h5p\factory())->get_core();
        $coreapi = \core_h5p\core::$coreApi; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        $expected = $coreapi['majorVersion'] . '.' . $coreapi['minorVersion'];

        $result = testable_create_mod_stream::execute($course->id, 1, 'Create an H5P activity', 0, null, 'en');
        // Pre-existing developer notice from execute_parameters().
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('h5p_core_api', $captured);
        $this->assertSame($expected, $captured['h5p_core_api']);

        // The version travels through the shared helper used by both the
        // individual flow and the course planning flow.
        $this->assertSame($expected, \local_coursegen\local\h5p_core_api::resolve());

        // Without the images option, no image policy travels in the request.
        $this->assertArrayNotHasKey('image_policy', $captured);
    }

    /**
     * MDL-INT-010: When the H5P framework version cannot be resolved, the request
     * is sent without that field and the generation continues.
     */
    public function test_generation_continues_when_h5p_core_api_unresolvable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $captured = null;
        $this->inject_api_service($captured);

        // Simulate an unresolvable framework version. The property is public
        // static on the H5P library class, so no reflection is needed.
        (new \core_h5p\factory())->get_core();
        $original = \core_h5p\core::$coreApi; // phpcs:ignore moodle.NamingConventions.ValidVariableName

        try {
            \core_h5p\core::$coreApi = []; // phpcs:ignore moodle.NamingConventions.ValidVariableName
            $result = testable_create_mod_stream::execute($course->id, 1, 'Create an H5P activity', 0, null, 'en');
        } finally {
            \core_h5p\core::$coreApi = $original; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        }

        // Pre-existing developer notice from execute_parameters().
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok'], 'Generation must continue without the version: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('h5p_core_api', $captured);

        // The shared helper reports the unresolvable version as null.
        try {
            \core_h5p\core::$coreApi = []; // phpcs:ignore moodle.NamingConventions.ValidVariableName
            $this->assertNull(\local_coursegen\local\h5p_core_api::resolve());
        } finally {
            \core_h5p\core::$coreApi = $original; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        }
    }

    /**
     * MDL-INT-011: Starting the individual AI generation requires a course
     * management capability, not just being enrolled. execute() enforces
     * moodle/course:manageactivities and local/coursegen:createactivitywithai
     * right after context validation, so an enrolled student receives ok=false,
     * the AI service is never called and no job record is persisted.
     */
    public function test_enrolled_student_cannot_start_generation(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $captured = null;
        $this->inject_api_service($captured);

        $result = testable_create_mod_stream::execute($course->id, 1, 'Create an H5P activity', 0, null, 'en');
        // Consume the pre-existing developer notice from execute_parameters()
        // so the capability assertion below fails cleanly on its own.
        $this->resetDebugging();

        $this->assertFalse(
            $result['ok'],
            'Starting AI generation must require moodle/course:manageactivities: an enrolled '
            . 'student must not be able to launch AI jobs and consume service credits.'
        );
        $this->assertNull($captured, 'The AI service must not be called for a user without permissions.');
        $this->assertSame(0, module_job::count_records(['courseid' => $course->id, 'userid' => $student->id]));
    }

    /**
     * MDL-CTR-002: The plugin consumes resource type, name, description, package
     * path and name, passing grade and module settings from the result, and
     * unknown additional fields do not break the creation.
     */
    public function test_unknown_extra_fields_in_result_are_tolerated(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $client->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename): \stored_file {
                global $USER;

                $fs = get_file_storage();
                $record = (object) [
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/',
                    'filename' => $filename,
                ];

                return $fs->create_file_from_string($record, h5p_package_fixture::bytes());
            }
        );
        api_client_factory::set_test_client($client);

        // Result with unknown additive fields at every level the plugin reads.
        $resultinfo = [
            'resource_type' => 'h5pactivity',
            'unknown_future_field' => 'ignored',
            'parameters' => [
                'modulename' => 'h5pactivity',
                'name' => 'Tolerant H5P',
                'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                'grade' => 100,
                'grademethod' => 1,
                'gradepass' => 70,
                'enabletracking' => 1,
                'reviewmode' => 1,
                'unknown_parameter' => ['nested' => 'ignored'],
                'mod_settings' => [
                    'file_path' => 'generated/packages/tolerant.h5p',
                    'file_name' => 'tolerant.h5p',
                    // Nested H5P-specific settings the plugin deliberately ignores.
                    'behaviour' => ['enableRetry' => true, 'unknown' => 'ignored'],
                ],
            ],
        ];

        $newcm = create_mod_service::create_from_ai_result($resultinfo, $course, 1);
        // The unknown nested H5P settings are reported as developer debugging
        // by the h5pactivity settings handler instead of silently ignored.
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);

        $record = $DB->get_record('h5pactivity', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame('Tolerant H5P', $record->name);

        $context = \context_module::instance($newcm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('tolerant.h5p', reset($files)->get_filename());
    }

    /**
     * MDL-CTR-002: A result missing the package path or name must fail with a
     * clear error instead of a PHP notice and a broken download URL.
     */
    public function test_missing_package_path_or_name_fails_clearly(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $resultinfo = [
            'resource_type' => 'h5pactivity',
            'parameters' => [
                'modulename' => 'h5pactivity',
                'name' => 'H5P without package path',
                'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                'grade' => 100,
                'grademethod' => 1,
                'gradepass' => 70,
                'enabletracking' => 1,
                'reviewmode' => 1,
                'mod_settings' => [
                    // No file_path: the download URL cannot be built.
                    'file_name' => 'sample-activity.h5p',
                ],
            ],
        ];

        try {
            create_mod_service::create_from_ai_result($resultinfo, $course, 1);
            $this->fail('An exception was expected for a result without file_path.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error_missing_package_info', 'local_coursegen'),
                $e->getMessage()
            );
        }

        // Nothing was created.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity'));
    }
}
