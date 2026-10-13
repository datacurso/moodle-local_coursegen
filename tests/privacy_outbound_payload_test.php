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
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use local_coursegen\external\activity_feedback;
use local_coursegen\external\activity_file_upload;
use local_coursegen\external\course_planning_feedback;
use local_coursegen\external\courseai_syllabus_upload;
use local_coursegen\external\create_mod_stream;
use local_coursegen\external\start_course_planning;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\image_generation\activities;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\module_job_service;
use local_coursegen\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * The privacy declaration of the external service must stay in step with what
 * actually leaves the site.
 *
 * Each test drives one outbound call with the API client replaced by a
 * recording double, captures the exact payload the plugin hands to the
 * transport, and asserts that every top-level key in it is declared in the
 * external location link of the privacy metadata. Adding a field to a payload
 * without declaring it turns these tests red.
 *
 * The external classes load lib/externallib.php, which requires each test to
 * run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\privacy\provider::get_metadata
 *
 * @runTestsInSeparateProcesses
 */
final class privacy_outbound_payload_test extends \advanced_testcase {
    /** @var array<int, array{path: string, payload: array}> Calls recorded by the client double. */
    private array $captured = [];

    /**
     * Keep any accidental real call away from the network.
     */
    protected function setUp(): void {
        parent::setUp();
        set_config('datacurso_service_url', 'https://invalid.invalid', 'local_coursegen');
    }

    /**
     * Drop the injected double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        $this->captured = [];
        parent::tearDown();
    }

    /**
     * The planning init payload only carries declared fields.
     */
    public function test_course_init_payload_fields_are_declared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        // Enable every optional branch so the richest possible payload travels.
        set_config('generationmode', activities::MODE_AUTO, 'local_coursegen');
        set_config('enablesubsections', 1, 'local_coursegen');

        $result = start_course_planning::execute('Create a short course about volcanoes', 'en', true, 0, true);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Planning must start: ' . ($result['message'] ?? ''));
        $payload = $this->captured_payload('/course/init');
        $this->assert_payload_keys_are_declared('POST /course/init', $payload);

        // Guard the fields this endpoint is known to send, so a silent removal
        // of the capture seam cannot make the assertion vacuous.
        foreach (['prompt', 'lang', 'with_images', 'with_subsections', 'subsections_available'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
    }

    /**
     * The activity init payload only carries declared fields.
     */
    public function test_activity_init_payload_fields_are_declared(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        set_config('generationmode', activities::MODE_AUTO, 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        // A stored course context makes the context_type field travel.
        $DB->insert_record('local_coursegen_course_context', (object) [
            'courseid' => $course->id,
            'context_type' => ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
            'prompt_text' => 'Teach it like a workshop.',
            'lang' => 'en',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $result = create_mod_stream::execute($course->id, 1, 'A quiz about volcanoes', 1);
        $this->resetDebugging();

        $this->assertTrue($result['ok'], 'Activity generation must start: ' . ($result['message'] ?? ''));
        $payload = $this->captured_payload('/activity/init');
        $this->assert_payload_keys_are_declared('POST /activity/init', $payload);

        foreach (['instructions', 'lang', 'with_images', 'context_type'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
    }

    /**
     * The syllabus multipart upload only carries declared fields, file
     * attributes included.
     */
    public function test_syllabus_upload_payload_fields_are_declared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.pdf', '%PDF-1.4 syllabus body');

        $result = courseai_syllabus_upload::execute((int) $session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $payload = $this->captured_payload('/course/sillabus/upload');
        $this->assert_payload_keys_are_declared('UPLOAD /course/sillabus/upload', $payload);

        $this->assertArrayHasKey('thread_id', $payload);
        $this->assertArrayHasKey('file', $payload);
        $this->assert_file_attributes_are_declared();
    }

    /**
     * The activity file multipart upload only carries declared fields, file
     * attributes included.
     */
    public function test_activity_file_upload_payload_fields_are_declared(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, (int) $USER->id, 'job-upload', 0, null, null, 1, null, 'completed');
        $draftitemid = $this->create_draft_file('notes.txt', 'Course notes.');

        $result = activity_file_upload::execute($course->id, 'job-upload', $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success']);
        $payload = $this->captured_payload('/activity/file/upload');
        $this->assert_payload_keys_are_declared('UPLOAD /activity/file/upload', $payload);

        $this->assertArrayHasKey('thread_id', $payload);
        $this->assertArrayHasKey('file', $payload);
        $this->assert_file_attributes_are_declared();
    }

    /**
     * The planning feedback payload only carries declared fields.
     */
    public function test_course_feedback_payload_fields_are_declared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        $session = $this->create_session();

        course_planning_feedback::execute((int) $session->get('id'), [
            'action' => 'feedback',
            'target_ids' => ['section-uuid-1'],
            'parent_section_id' => 'section-uuid-0',
            'position' => 2,
            'instruction' => 'Make the second section shorter.',
        ]);
        $this->resetDebugging();

        $payload = $this->captured_payload('/course/feedback');
        $this->assert_payload_keys_are_declared('POST /course/feedback', $payload);

        $this->assertArrayHasKey('thread_id', $payload);
        $this->assertArrayHasKey('pending_action', $payload);
    }

    /**
     * The activity feedback payload only carries declared fields.
     */
    public function test_activity_feedback_payload_fields_are_declared(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->inject_recording_client();

        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, (int) $USER->id, 'job-feedback', 0, null, null, 1, null, 'completed');

        activity_feedback::execute($course->id, 'job-feedback', 'adjust', 'Shorten the introduction.');
        $this->resetDebugging();

        $payload = $this->captured_payload('/activity/feedback');
        $this->assert_payload_keys_are_declared('POST /activity/feedback', $payload);

        foreach (['thread_id', 'approval_status', 'instruction'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
    }

    /**
     * Every field the provider transport layer merges into each request body
     * must be declared too.
     *
     * The keys are read from the provider source so a new one added there
     * fails this test instead of travelling undeclared. The documented list is
     * the fallback for sites where the provider plugin is not installed.
     */
    public function test_transport_default_fields_are_declared(): void {
        $this->resetAfterTest();

        $fields = $this->transport_default_fields();
        $this->assertNotEmpty($fields, 'The transport default payload fields could not be resolved.');

        $declared = $this->declared_fields();
        foreach ($fields as $field) {
            $this->assertContains(
                $field,
                $declared,
                "The provider transport merges '{$field}' into every request body but it is not declared in "
                    . 'the datacurso_course_service external location link.'
            );
        }
    }

    /**
     * Fields that the provider transport merges into every request body.
     *
     * @return string[]
     */
    private function transport_default_fields(): array {
        global $CFG;

        $source = $CFG->dirroot . '/ai/provider/datacurso/classes/httpclient/datacurso_api_base.php';
        if (is_readable($source)) {
            $contents = (string) file_get_contents($source);
            if (preg_match('/\$defaultpayload\s*=\s*\[(.*?)\];/s', $contents, $block)) {
                preg_match_all("/'([a-z0-9_]+)'\s*=>/i", $block[1], $keys);
                if (!empty($keys[1])) {
                    return array_values(array_unique($keys[1]));
                }
            }
        }

        // Documented fallback: see aiprovider_datacurso\httpclient\datacurso_api_base::send_request().
        return ['site_id', 'userid', 'timezone', 'lang', 'site_url'];
    }

    /**
     * Assert that each top-level key of a captured payload is declared.
     *
     * @param string $label Human readable call label used in failure messages.
     * @param array $payload Captured payload.
     */
    private function assert_payload_keys_are_declared(string $label, array $payload): void {
        $declared = $this->declared_fields();
        $this->assertNotEmpty($payload, "No payload was captured for {$label}.");

        $undeclared = array_values(array_diff(array_map('strval', array_keys($payload)), $declared));
        $this->assertSame(
            [],
            $undeclared,
            "{$label} sends " . implode(', ', $undeclared) . ' to the external service, but '
                . 'those fields are not declared in the datacurso_course_service external location link '
                . 'of the privacy metadata.'
        );
    }

    /**
     * Assert the attributes travelling with a multipart file part are declared.
     */
    private function assert_file_attributes_are_declared(): void {
        $declared = $this->declared_fields();
        foreach (['filename', 'mimetype'] as $attribute) {
            $this->assertContains(
                $attribute,
                $declared,
                "The multipart file part carries its '{$attribute}' to the external service but it is not declared."
            );
        }
    }

    /**
     * Fields declared in the external location link of the privacy metadata.
     *
     * @return string[]
     */
    private function declared_fields(): array {
        $collection = provider::get_metadata(new collection('local_coursegen'));
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof external_location && $item->get_name() === 'datacurso_course_service') {
                return array_keys($item->get_privacy_fields());
            }
        }

        return [];
    }

    /**
     * The payload recorded for an endpoint.
     *
     * @param string $path Endpoint path.
     * @return array
     */
    private function captured_payload(string $path): array {
        foreach ($this->captured as $call) {
            if ($call['path'] === $path) {
                return $call['payload'];
            }
        }

        $this->fail('No outbound call was recorded for ' . $path . '.');
    }

    /**
     * Replace the API client with a double that records every payload.
     */
    private function inject_recording_client(): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'upload_file', 'get_base_url'])
            ->getMock();

        $client->method('get_base_url')->willReturn('https://ai.invalid');

        $client->method('request')->willReturnCallback(
            function (string $method, string $path, array $body = []): array {
                $this->captured[] = ['path' => $path, 'payload' => $body];
                return ['thread_id' => 'thread-1', 'status' => 'started'];
            }
        );

        // Mirrors datacurso_api_base::upload_file(): the extra parameters travel
        // next to a 'file' part built from the stored file.
        $client->method('upload_file')->willReturnCallback(
            function (string $path, \stored_file $file, array $extraparams = []): array {
                $this->captured[] = [
                    'path' => $path,
                    'payload' => $extraparams + ['file' => $file->get_filename()],
                ];
                return ['ok' => true];
            }
        );

        api_client_factory::set_test_client($client);
    }

    /**
     * Create a pending planning session owned by the current user.
     *
     * @return course_session
     */
    private function create_session(): course_session {
        global $USER;

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => 'thread-1',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['local_coursegen_context_type' => 'customprompt']),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Put a file into a fresh draft area of the current user.
     *
     * @param string $filename File name to store.
     * @param string $content File content.
     * @return int Draft item id.
     */
    private function create_draft_file(string $filename, string $content): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object) [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);

        return $draftitemid;
    }
}
