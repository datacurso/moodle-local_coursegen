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

use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\template\template_service;

/**
 * Starting a template generation with and without a syllabus.
 *
 * The service is mocked through the testable fixture, so no request is ever performed. The fixture loads
 * lib/externallib.php, which requires each test to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_template_generation
 *
 * @runTestsInSeparateProcesses
 */
final class start_template_generation_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_start_template_generation.php');
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        testable_start_template_generation::$mockservice = null;
        parent::tearDown();
    }

    /**
     * A saved template of a new course, saved by the current user.
     *
     * @return int Template id.
     */
    private function template(): int {
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        return (new template_service())->save(0, (int) $course->id, 'Template', '', [], (int) $USER->id);
    }

    /**
     * A draft area of the current user holding one file.
     *
     * @param string $content Bytes of the file.
     * @return int Draft item id.
     */
    private function draft_with(string $content = 'PDFDATA'): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'syllabus.pdf',
        ], $content);
        return $draftid;
    }

    /**
     * How many files a draft area still holds.
     *
     * @param int $draftid Draft item id.
     * @return int Count of files.
     */
    private function draft_count(int $draftid): int {
        global $USER;
        $context = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftid, 'id', false);
        return count($files);
    }

    /**
     * A mock of the service whose init answers a thread.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject The mock, installed in the fixture.
     */
    private function service(): \PHPUnit\Framework\MockObject\MockObject {
        $service = $this->createMock(template_ai_api_service::class);
        $service->method('init')->willReturn('t-5');
        $streamurl = 'https://moodle.test/local/coursegen/stream.php?streamtype=template&threadid=t-5';
        $service->method('stream_url')->willReturn($streamurl);
        testable_start_template_generation::$mockservice = $service;
        return $service;
    }

    /**
     * The events of a sink that record a transfer of a file to the service.
     *
     * @param array $events Events of the sink.
     * @return array The transfer events.
     */
    private function transfer_events(array $events): array {
        $transfers = [];
        foreach ($events as $event) {
            if ($event instanceof event\external_transfer_initiated) {
                $transfers[] = $event;
            }
        }
        return $transfers;
    }

    /**
     * The sessions of the current user.
     *
     * @return \stdClass[] Session records.
     */
    private function sessions(): array {
        global $DB, $USER;
        $records = $DB->get_records('local_coursegen_course_sessions', ['userid' => $USER->id]);
        return array_values($records);
    }

    /**
     * A syllabus is uploaded to the thread right after the run is created, and the session remembers its name.
     */
    public function test_the_syllabus_is_uploaded_to_the_new_thread(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $draftid = $this->draft_with();
        $service = $this->service();
        $once = $this->once();
        $file = $this->isInstanceOf(\stored_file::class);
        $service->expects($once)->method('upload_syllabus')->with('t-5', $file);

        $result = testable_start_template_generation::execute($templateid, 'Make it', $draftid);

        $this->assertSame('t-5', $result['threadid']);
        $sessions = $this->sessions();
        $this->assertCount(1, $sessions);
        $coursedata = json_decode($sessions[0]->coursedata, true);
        $this->assertSame('syllabus.pdf', $coursedata['syllabus']['filename']);
        $this->assertSame(7, $coursedata['syllabus']['filesize']);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * The transfer of the file to the service is recorded in an event without the content of the file.
     */
    public function test_the_transfer_is_recorded_without_the_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $draftid = $this->draft_with('SECRETBODY');
        $this->service();
        $sink = $this->redirectEvents();

        testable_start_template_generation::execute($templateid, '', $draftid);

        $recorded = $sink->get_events();
        $events = $this->transfer_events($recorded);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame('syllabus.pdf', $event->other['filename']);
        $this->assertSame(10, $event->other['filesize']);
        $encoded = json_encode($event->other);
        $this->assertStringNotContainsString('SECRETBODY', $encoded);
    }

    /**
     * A generation without a syllabus uploads nothing and records no file.
     */
    public function test_no_syllabus_means_no_upload_and_no_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $service = $this->service();
        $never = $this->never();
        $service->expects($never)->method('upload_syllabus');
        $sink = $this->redirectEvents();

        testable_start_template_generation::execute($templateid, 'Make it', 0);

        $events = $sink->get_events();

        $this->assertCount(0, $events);
        $coursedata = json_decode($this->sessions()[0]->coursedata, true);
        $this->assertArrayNotHasKey('syllabus', $coursedata);
    }

    /**
     * A syllabus the service refuses stops the start with a message, creates no session and empties the draft.
     */
    public function test_a_refused_syllabus_creates_no_session(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $draftid = $this->draft_with();
        $service = $this->service();
        $refusal = new \moodle_exception('httperror', 'aiprovider_datacurso', '', 422);
        $service->method('upload_syllabus')->willThrowException($refusal);
        $sink = $this->redirectEvents();

        try {
            testable_start_template_generation::execute($templateid, '', $draftid);
            $this->fail('A refused syllabus must stop the start.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templatesyllabusrejected', $exception->errorcode);
        }

        $sessions = $this->sessions();

        $this->assertCount(0, $sessions);
        $events = $sink->get_events();
        $this->assertCount(0, $events);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * A draft area without a file stops the start with a message and creates no session.
     */
    public function test_a_missing_file_creates_no_session(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $this->service();

        try {
            $draftid = file_get_unused_draft_itemid();
            testable_start_template_generation::execute($templateid, '', $draftid);
            $this->fail('A syllabus that is not in its draft area must stop the start.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templatesyllabusmissing', $exception->errorcode);
        }

        $sessions = $this->sessions();

        $this->assertCount(0, $sessions);
    }

    /**
     * Attaching a syllabus needs the capability to upload syllabi, and nothing reaches the service without it.
     */
    public function test_attaching_a_syllabus_needs_the_upload_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coursegen:createtemplatecoursewithai', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $draftid = $this->draft_with();
        $service = $this->createMock(template_ai_api_service::class);
        $never = $this->never();
        $service->expects($never)->method('init');
        $never = $this->never();
        $service->expects($never)->method('upload_syllabus');
        testable_start_template_generation::$mockservice = $service;

        $this->expectException(\required_capability_exception::class);
        testable_start_template_generation::execute($templateid, '', $draftid);
    }

    /**
     * A user that may start a generation but not attach a syllabus can still start one without a file.
     */
    public function test_starting_without_a_syllabus_does_not_need_the_upload_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coursegen:createtemplatecoursewithai', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->service();

        $result = testable_start_template_generation::execute($templateid, 'Make it', 0);

        $this->assertSame('t-5', $result['threadid']);
    }

    /**
     * A start with no request and no file is refused in plain words, creates no session and reaches no service.
     *
     * @dataProvider nothing_to_work_from_provider
     * @param string $prompt The request, empty or only blanks.
     */
    public function test_a_start_with_no_request_and_no_file_is_refused(string $prompt): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $service = $this->createMock(template_ai_api_service::class);
        $never = $this->never();
        $service->expects($never)->method('init');
        $never = $this->never();
        $service->expects($never)->method('upload_syllabus');
        testable_start_template_generation::$mockservice = $service;

        try {
            testable_start_template_generation::execute($templateid, $prompt, 0);
            $this->fail('A start with nothing to work from must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templatenothingtostart', $exception->errorcode);
        }

        $sessions = $this->sessions();

        $this->assertCount(0, $sessions);
    }

    /**
     * What counts as no request.
     *
     * @return array Each case holds the request text.
     */
    public static function nothing_to_work_from_provider(): array {
        return [
            'empty' => [''],
            'one space' => [' '],
            'blanks' => ["  \n\t "],
        ];
    }

    /**
     * The refusal says what to do, without a word about how the generation works.
     */
    public function test_the_refusal_names_what_to_do(): void {
        $this->resetAfterTest();
        $english = get_string('templatenothingtostart', 'local_coursegen');

        $this->assertStringContainsString('attach', $english);
        $this->assertStringNotContainsStringIgnoringCase('service', $english);
        $this->assertStringNotContainsStringIgnoringCase('token', $english);
    }

    /**
     * A request alone starts the generation, with no file and no upload.
     */
    public function test_a_request_alone_starts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $service = $this->service();
        $never = $this->never();
        $service->expects($never)->method('upload_syllabus');

        $result = testable_start_template_generation::execute($templateid, 'Adapt it to nursing', 0);

        $this->assertSame('t-5', $result['threadid']);
        $this->assertCount(1, $this->sessions());
    }

    /**
     * A file alone starts the generation, with an empty request.
     */
    public function test_a_file_alone_starts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template();
        $draftid = $this->draft_with();
        $service = $this->service();
        $once = $this->once();
        $service->expects($once)->method('upload_syllabus');

        $result = testable_start_template_generation::execute($templateid, '', $draftid);

        $this->assertSame('t-5', $result['threadid']);
        $this->assertCount(1, $this->sessions());
    }
}
