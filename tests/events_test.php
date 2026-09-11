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
use context_course;
use context_system;
use context_user;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * Audit event tests for the AI generation lifecycle.
 *
 * The AI service is mocked through the testable fixtures, so no network
 * request is ever performed. The fixtures load lib/externallib.php, which
 * requires each test to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\event\generation_job_started
 * @covers     \local_coursegen\event\generation_result_applied
 * @covers     \local_coursegen\event\generation_failed
 * @covers     \local_coursegen\event\generation_denied
 * @covers     \local_coursegen\event\external_transfer_initiated
 *
 * @runTestsInSeparateProcesses
 */
final class events_test extends \advanced_testcase {
    /**
     * Load the testable subclasses in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_create_mod.php');
        require_once(__DIR__ . '/fixtures/testable_create_mod_stream.php');
        require_once(__DIR__ . '/fixtures/testable_courseai_syllabus_upload.php');
        require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');

        // See create_mod_permissions_test: give the front page real section rows.
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing(get_site(), [0, 1]);
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_create_mod::$mockservice = null;
        testable_create_mod_stream::$mockservice = null;
        testable_courseai_syllabus_upload::$mockservice = null;
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Starting an activity generation job fires generation_job_started with job data and no prompt.
     */
    public function test_generation_job_started_event_on_activity_stream(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['start_activity', 'get_mod_streaming_url_for_job'])
            ->getMock();
        $service->method('start_activity')
            ->willReturn(['thread_id' => 'job-1', 'status' => 'queued']);
        $service->method('get_mod_streaming_url_for_job')
            ->willReturn('https://ai.example.com/api/v1/activity/stream/job-1');
        testable_create_mod_stream::$mockservice = $service;

        $sink = $this->redirectEvents();
        $result = testable_create_mod_stream::execute($course->id, 1, 'A very personal prompt', 1, null, 'en');
        $this->resetDebugging();

        $this->assertTrue($result['ok']);
        $events = $this->events_of_class($sink, event\generation_job_started::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(context_course::instance($course->id)->id, $event->get_context()->id);
        $this->assertSame('job-1', $event->other['job_id']);
        $this->assertEquals(1, $event->other['generate_images']);
        // No personal content may travel in the event.
        $this->assertStringNotContainsString('personal prompt', json_encode($event->other));
    }

    /**
     * Applying a generation result fires generation_result_applied with job id and cmid.
     */
    public function test_generation_result_applied_event_on_create_mod(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, $USER->id, 'job-ok', 0, null, null, 1, null, 'completed');
        $this->inject_api_service($this->h5p_activity_result());
        $this->inject_download_client();

        $sink = $this->redirectEvents();
        $result = testable_create_mod::execute($course->id, 1, 'job-ok');
        $this->resetDebugging();

        $this->assertTrue($result['ok'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $events = $this->events_of_class($sink, event\generation_result_applied::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(context_course::instance($course->id)->id, $event->get_context()->id);
        $this->assertSame('job-ok', $event->other['jobid']);

        // The cmid in the event is the created course module of this course.
        global $DB;
        $this->assertTrue(
            $DB->record_exists('course_modules', ['id' => $event->other['cmid'], 'course' => $course->id])
        );
    }

    /**
     * A failing generation fires generation_failed carrying only the sanitized reason class.
     */
    public function test_generation_failed_event_on_create_mod(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, $USER->id, 'job-fail', 0, null, null, 1, null, 'completed');

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_activity_result'])
            ->getMock();
        $service->method('get_activity_result')
            ->willThrowException(new \RuntimeException('secret internal detail'));
        testable_create_mod::$mockservice = $service;

        $sink = $this->redirectEvents();
        $result = testable_create_mod::execute($course->id, 1, 'job-fail');
        $this->resetDebugging();

        $this->assertFalse($result['ok']);
        $events = $this->events_of_class($sink, event\generation_failed::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame('RuntimeException', $event->other['reason']);
        // The exception message must never leak into the event payload.
        $this->assertStringNotContainsString('secret internal detail', json_encode($event->other));
    }

    /**
     * A capability rejection fires generation_denied with the missing capability.
     */
    public function test_generation_denied_event_on_create_mod(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        module_job_service::create_job($course->id, $student->id, 'job-denied', 0, null, null, 1, null, 'completed');
        $this->inject_api_service($this->h5p_activity_result());

        $sink = $this->redirectEvents();
        $result = testable_create_mod::execute($course->id, 1, 'job-denied');
        $this->resetDebugging();

        $this->assertFalse($result['ok']);
        $events = $this->events_of_class($sink, event\generation_denied::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        // The event carries the localized name of the missing capability.
        $this->assertSame(get_capability_string('moodle/course:manageactivities'), $event->other['capability']);
    }

    /**
     * A successful syllabus upload fires external_transfer_initiated with name and size, never content.
     */
    public function test_external_transfer_initiated_event_on_syllabus_upload(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = new course_session(0, (object) [
            'userid' => (int)$USER->id,
            'session_id' => 'thread-1',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['local_coursegen_context_type' => 'customprompt']),
        ]);
        $session->create();

        // Put a PDF into the user draft area.
        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $fs->create_file_from_string((object) [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 syllabus body');

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upload_syllabus'])
            ->getMock();
        $service->method('upload_syllabus')->willReturn(['ok' => true]);
        testable_courseai_syllabus_upload::$mockservice = $service;

        $sink = $this->redirectEvents();
        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $events = $this->events_of_class($sink, event\external_transfer_initiated::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(context_system::instance()->id, $event->get_context()->id);
        $this->assertSame('syllabus.pdf', $event->other['filename']);
        $this->assertGreaterThan(0, $event->other['filesize']);
        $this->assertStringNotContainsString('syllabus body', json_encode($event->other));
    }

    /**
     * Filter sink events by class.
     *
     * @param \phpunit_event_sink $sink Event sink.
     * @param string $classname Fully qualified event class name.
     * @return array
     */
    private function events_of_class(\phpunit_event_sink $sink, string $classname): array {
        return array_values(array_filter($sink->get_events(), static function ($event) use ($classname): bool {
            return $event instanceof $classname;
        }));
    }

    /**
     * Inject an ai_course_api_service mock returning the given activity result.
     *
     * @param array $result Activity result payload returned by get_activity_result().
     * @return void
     */
    private function inject_api_service(array $result): void {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_activity_result'])
            ->getMock();
        $service->method('get_activity_result')->willReturn($result);

        testable_create_mod::$mockservice = $service;
    }

    /**
     * Inject an ai_course_api mock whose download_file() returns a real draft file.
     *
     * @return void
     */
    private function inject_download_client(): void {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();

        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename): \stored_file {
                global $USER;

                $fs = get_file_storage();
                $record = (object) [
                    'contextid' => context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/',
                    'filename' => $filename,
                ];

                return $fs->create_file_from_string($record, h5p_package_fixture::bytes());
            }
        );

        api_client_factory::set_test_client($mock);
    }

    /**
     * Build an AI activity result payload for an H5P activity.
     *
     * @return array
     */
    private function h5p_activity_result(): array {
        return [
            'resource_type' => 'h5pactivity',
            'parameters' => [
                'modulename' => 'h5pactivity',
                'name' => 'AI generated H5P',
                'introeditor' => ['text' => '<p>AI generated intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'visible' => 1,
                'cmidnumber' => '',
                'grade' => 100,
                'grademethod' => 1,
                'gradepass' => 70,
                'enabletracking' => 1,
                'reviewmode' => 1,
                'mod_settings' => [
                    'file_path' => 'generated/packages/sample-activity.h5p',
                    'file_name' => 'sample-activity.h5p',
                ],
            ],
        ];
    }
}
