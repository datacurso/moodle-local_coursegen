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

use context_course;
use context_user;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * Server-side validation of the syllabus upload endpoint.
 *
 * The filepicker restricts the accepted types in the browser only, so the save
 * endpoint must re-check what was actually persisted before it reaches the
 * external service.
 *
 * The fixtures load lib/externallib.php, which requires each test to run in an
 * isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 *
 * @runTestsInSeparateProcesses
 */
final class courseai_syllabus_upload_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_courseai_syllabus_upload.php');
    }

    /**
     * Reset the injected doubles and the web service context restriction.
     */
    protected function tearDown(): void {
        testable_courseai_syllabus_upload::$mockservice = null;
        \core_external\external_api::set_context_restriction(null);
        parent::tearDown();
    }

    /**
     * A file whose extension is outside the allow list is rejected and never
     * reaches the external service.
     */
    public function test_upload_rejects_a_disallowed_extension(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('payload.exe', 'MZ not a syllabus at all');
        $service = $this->mock_service_expecting_no_upload();
        testable_courseai_syllabus_upload::$mockservice = $service;

        $sink = $this->redirectEvents();
        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['filename']);
        $this->assertSame(
            get_string('error_syllabus_invalid_file_type', 'local_coursegen'),
            $result['message']
        );
        $this->assertCount(0, $sink->get_events());
        $this->assertSame([], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * A file whose stored mimetype is outside the allow list is rejected even
     * when its extension looks acceptable.
     */
    public function test_upload_rejects_a_disallowed_mimetype(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        // The extension is allowed but the stored mimetype is forced to an
        // executable one, mimicking a crafted draft record.
        $draftitemid = $this->create_draft_file('syllabus.pdf', '%PDF-1.4 body', 'application/x-msdownload');
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_no_upload();

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame(
            get_string('error_syllabus_invalid_file_type', 'local_coursegen'),
            $result['message']
        );
        $this->assertSame([], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * A file over the server-side size limit is rejected with its own message.
     */
    public function test_upload_rejects_a_file_over_the_size_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $oversized = str_repeat('A', \local_coursegen\external\courseai_syllabus_upload::MAX_SYLLABUS_BYTES + 1);
        $draftitemid = $this->create_draft_file('syllabus.pdf', $oversized);
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_no_upload();

        $sink = $this->redirectEvents();
        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame(
            get_string('error_syllabus_file_too_large', 'local_coursegen'),
            $result['message']
        );
        $this->assertCount(0, $sink->get_events());
        $this->assertSame([], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * The happy path is untouched: an allowed PDF is stored and transferred.
     */
    public function test_upload_accepts_a_valid_pdf(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.pdf', '%PDF-1.4 syllabus body');

        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upload_syllabus'])
            ->getMock();
        $service->expects($this->once())->method('upload_syllabus')->willReturn(['ok' => true]);
        testable_courseai_syllabus_upload::$mockservice = $service;

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame('syllabus.pdf', $result['filename']);
        $this->assertSame(['syllabus.pdf'], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * The endpoint honours the web service context restriction like every one
     * of its siblings: it calls validate_context() before doing any work.
     */
    public function test_upload_honours_the_web_service_context_restriction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.pdf', '%PDF-1.4 syllabus body');
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_no_upload();

        $course = $this->getDataGenerator()->create_course();
        \core_external\external_api::set_context_restriction(context_course::instance($course->id));

        $this->expectException(\core_external\restricted_context_exception::class);
        testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
    }

    /**
     * Create a pending planning session owned by the current user.
     *
     * @return course_session
     */
    private function create_session(): course_session {
        global $USER;

        $session = new course_session(0, (object) [
            'userid' => (int)$USER->id,
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
     * @param string|null $mimetype Force a stored mimetype, or null to infer it.
     * @return int Draft item id.
     */
    private function create_draft_file(string $filename, string $content, ?string $mimetype = null): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $record = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        if ($mimetype !== null) {
            $record['mimetype'] = $mimetype;
        }

        get_file_storage()->create_file_from_string((object) $record, $content);

        return $draftitemid;
    }

    /**
     * Names of the files stored in the syllabus area of a session.
     *
     * @param int $sessionid Session record id.
     * @return string[]
     */
    private function stored_syllabus_files(int $sessionid): array {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_coursegen',
            'syllabus',
            $sessionid,
            'id',
            false
        );

        return array_values(array_map(static function (\stored_file $file): string {
            return $file->get_filename();
        }, $files));
    }

    /**
     * An API service double that fails the test if the transfer is attempted.
     *
     * @return ai_course_api_service
     */
    private function mock_service_expecting_no_upload(): ai_course_api_service {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upload_syllabus'])
            ->getMock();
        $service->expects($this->never())->method('upload_syllabus');

        return $service;
    }
}
