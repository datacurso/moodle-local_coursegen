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

use core\context\course;
use core\context\system;
use local_coursegen\external\courseai_syllabus_upload;
use local_coursegen\external\create_mod;
use local_coursegen\external\create_mod_stream;
use local_coursegen\external\start_course_planning;
use local_coursegen\local\service\module_job_service;
use local_coursegen\local\warning_collector;
use local_coursegen\tests\api_testcase;

/**
 * Audit event tests for the AI generation lifecycle.
 *
 * The AI service is mocked through the factory seam, so no network request
 * is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\event\generation_job_started
 * @covers     \local_coursegen\event\generation_result_applied
 * @covers     \local_coursegen\event\generation_failed
 * @covers     \local_coursegen\event\generation_denied
 * @covers     \local_coursegen\event\generation_warning
 * @covers     \local_coursegen\event\external_transfer_initiated
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_job_started::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_result_applied::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_failed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_denied::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_warning::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\external_transfer_initiated::class)]
final class events_test extends api_testcase {
    /**
     * Give the front page real section rows.
     */
    protected function setUp(): void {
        parent::setUp();

        // See create_mod_permissions_test: give the front page real section rows.
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing(get_site(), [0, 1]);
    }

    /**
     * Starting an activity generation job fires generation_job_started with job data and no prompt.
     */
    public function test_generation_job_started_event_on_activity_stream(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $this->inject_api_service([
            'start_activity' => ['thread_id' => 'job-1', 'status' => 'queued'],
            'get_mod_streaming_url_for_job' => 'https://ai.example.com/api/v1/activity/stream/job-1',
        ]);

        $sink = $this->redirectEvents();
        $result = create_mod_stream::execute($course->id, 1, 'A very personal prompt', 1, null, 'en');
        $this->resetDebugging();

        $this->assertTrue($result['ok']);
        $events = $this->events_of_class($sink, event\generation_job_started::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(course::instance($course->id)->id, $event->get_context()->id);
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
        $this->inject_api_service(['get_activity_result' => $this->h5p_activity_result()]);
        $this->inject_download_client();

        $sink = $this->redirectEvents();
        $result = create_mod::execute($course->id, 1, 'job-ok');
        $this->resetDebugging();

        $this->assertTrue($result['ok'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $events = $this->events_of_class($sink, event\generation_result_applied::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(course::instance($course->id)->id, $event->get_context()->id);
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

        $this->inject_api_service(['get_activity_result' => new \RuntimeException('secret internal detail')]);

        $sink = $this->redirectEvents();
        $result = create_mod::execute($course->id, 1, 'job-fail');
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
        $this->inject_api_service(['get_activity_result' => $this->h5p_activity_result()]);

        $sink = $this->redirectEvents();
        $result = create_mod::execute($course->id, 1, 'job-denied');
        $this->resetDebugging();

        $this->assertFalse($result['ok']);
        $events = $this->events_of_class($sink, event\generation_denied::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        // The event carries the localized name of the missing capability.
        $this->assertSame(get_capability_string('moodle/course:manageactivities'), $event->other['capability']);
    }

    /**
     * A non-fatal step failure while applying the result fires generation_warning in the course
     * context and the warning is returned to the client next to the created activity.
     */
    public function test_generation_warning_event_on_create_mod(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);
        module_job_service::create_job($course->id, $USER->id, 'job-warn', 0, null, null, 1, null, 'completed');

        $this->inject_api_service(['get_activity_result' => [
            'resource_type' => 'data',
            'parameters' => [
                'modulename' => 'data',
                'name' => 'Reviews',
                'introeditor' => ['text' => '<p>Reviews</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'timeavailablefrom' => 0,
                'timeavailableto' => 0,
                'timeviewfrom' => 0,
                'timeviewto' => 0,
                'assessed' => 0,
                'scale' => 0,
                'visible' => 1,
                'cmidnumber' => '',
                'mod_settings' => ['fields' => [['type' => 'bogustype', 'name' => 'Odd']]],
            ],
        ]]);

        $sink = $this->redirectEvents();
        $result = create_mod::execute($course->id, 1, 'job-warn');
        $this->resetDebugging();

        $this->assertTrue($result['ok'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(
            [get_string('generationwarning_data_field', 'local_coursegen', 'Odd')],
            $result['warnings']
        );

        $events = $this->events_of_class($sink, event\generation_warning::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(course::instance($course->id)->id, $event->get_context()->id);
        $this->assertSame('data', $event->other['modname']);
        $this->assertSame(warning_collector::STEP_DATA_FIELD, $event->other['step']);
        $this->assertSame('Odd', $event->other['subject']);
        $this->assertLessThanOrEqual(warning_collector::REASON_MAX_LENGTH, \core_text::strlen($event->other['reason']));
        // The activity itself was still created and reported.
        $this->assertCount(1, $this->events_of_class($sink, event\generation_result_applied::class));
    }

    /**
     * A lookup that falls back to a default while preparing the course planning payload fires
     * generation_warning in the system context (no module yet), and the planning still starts.
     */
    public function test_generation_warning_event_on_course_planning_fallback(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        warning_collector::set_test_failure(
            warning_collector::STEP_FILETYPE_CATALOG,
            new \RuntimeException('filetypes unavailable')
        );
        $captured = null;
        $this->inject_api_service([
            'start_course_planning' => function (array $payload) use (&$captured): array {
                $captured = $payload;
                return ['thread_id' => 'thread-plan'];
            },
            'get_course_streaming_url' => 'https://ai.example.com/api/v1/course/stream/thread-plan',
        ]);

        $sink = $this->redirectEvents();
        $result = start_course_planning::execute('Create a short course about volcanoes', 'en');
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $this->assertTrue($result['success'], 'Planning must start: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('filetype_groups', $captured);

        $events = $this->events_of_class($sink, event\generation_warning::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(system::instance()->id, $event->get_context()->id);
        $this->assertSame('', $event->other['modname']);
        $this->assertSame(warning_collector::STEP_FILETYPE_CATALOG, $event->other['step']);
        $this->assertSame('filetypes unavailable', $event->other['reason']);
        $this->assertCount(1, $this->events_of_class($sink, event\generation_job_started::class));
    }

    /**
     * A successful syllabus upload fires external_transfer_initiated with name and size, never content.
     */
    public function test_external_transfer_initiated_event_on_syllabus_upload(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_session([
            'userid' => (int)$USER->id,
            'session_id' => 'thread-1',
            'coursedata' => ['local_coursegen_context_type' => 'customprompt'],
        ]);

        // Put a PDF into the user draft area.
        $draftitemid = file_get_unused_draft_itemid();
        $this->create_draft_file('syllabus.pdf', '%PDF-1.4 syllabus body', $draftitemid);

        $this->inject_api_service(['upload_syllabus' => ['ok' => true]]);

        $sink = $this->redirectEvents();
        $result = courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $events = $this->events_of_class($sink, event\external_transfer_initiated::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(system::instance()->id, $event->get_context()->id);
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
}
