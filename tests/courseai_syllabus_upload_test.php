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
     * The stored mimetype is not evidence: Moodle derives it from the file
     * name, so a crafted draft record that claims an accepted mimetype must
     * still be rejected when the bytes say otherwise.
     */
    public function test_upload_rejects_a_forged_draft_mimetype_record(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        // Both the extension and the recorded mimetype are the ones an
        // attacker would choose; only the content gives the file away.
        $draftitemid = $this->create_draft_file(
            'syllabus.pdf',
            self::windows_executable_bytes(),
            'application/pdf'
        );
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
     * A Windows executable renamed to .pdf is rejected. Moodle stores
     * application/pdf for it because the mimetype comes from the name, so only
     * a content check can catch it.
     */
    public function test_upload_rejects_an_executable_renamed_to_pdf(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('fake.pdf', self::windows_executable_bytes());
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_no_upload();

        $sink = $this->redirectEvents();
        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertFalse($result['success']);
        $this->assertSame(
            get_string('error_syllabus_invalid_file_type', 'local_coursegen'),
            $result['message']
        );
        $this->assertCount(0, $sink->get_events());
        $this->assertSame([], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * The content must match the declared extension: a real PDF renamed to
     * .txt satisfies a flat mimetype allow list but is still a mismatch.
     */
    public function test_upload_rejects_a_pdf_renamed_to_txt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('programa.txt', self::pdf_bytes());
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
     * A .txt holding something libmagic cannot name is still a legitimate
     * syllabus. Source-like text sniffs as document/unknown, so demanding a
     * recognised text mimetype would reject real files.
     */
    public function test_upload_accepts_a_txt_whose_content_sniffs_as_unknown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file(
            'syllabus.txt',
            "#include <stdio.h>\nint main(){return 0;}\n"
        );
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_one_upload();

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(['syllabus.txt'], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * An almost empty .txt sniffs as application/octet-stream and is still a
     * legitimate text file.
     */
    public function test_upload_accepts_an_almost_empty_txt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.txt', ' ');
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_one_upload();

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(['syllabus.txt'], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * A .txt carrying NUL bytes is binary content, whatever its name says.
     */
    public function test_upload_rejects_a_txt_containing_nul_bytes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.txt', "Unit 1\x00\x01\x02 binary payload\x00");
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
     * A real OOXML document is accepted.
     */
    public function test_upload_accepts_a_valid_docx(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.docx', $this->docx_bytes());
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_one_upload();

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(['syllabus.docx'], $this->stored_syllabus_files((int)$session->get('id')));
    }

    /**
     * Older libmagic builds cannot tell an OOXML container apart from a plain
     * zip, so application/zip must stay acceptable for .docx.
     */
    public function test_upload_accepts_a_docx_that_sniffs_as_a_plain_zip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->create_session();
        $draftitemid = $this->create_draft_file('syllabus.docx', $this->plain_zip_bytes());
        testable_courseai_syllabus_upload::$mockservice = $this->mock_service_expecting_one_upload();

        $result = testable_courseai_syllabus_upload::execute((int)$session->get('id'), $draftitemid);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Upload must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame(['syllabus.docx'], $this->stored_syllabus_files((int)$session->get('id')));
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
     * Bytes of a Windows executable: the MZ signature plus padding.
     *
     * @return string
     */
    private static function windows_executable_bytes(): string {
        return "\x4d\x5a\x90\x00" . str_repeat("\x00", 256);
    }

    /**
     * Bytes of a small but genuine PDF document.
     *
     * @return string
     */
    private static function pdf_bytes(): string {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * Bytes of a minimal but genuine OOXML word processing document.
     *
     * @return string
     */
    private function docx_bytes(): string {
        $contenttypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument'
            . '.wordprocessingml.document.main+xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships'
            . '/officeDocument" Target="word/document.xml"/></Relationships>';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>Syllabus</w:t></w:r></w:p></w:body></w:document>';

        // The content types part must come first for libmagic to recognise the
        // package as OOXML rather than as a bare zip.
        return $this->zip_bytes([
            '[Content_Types].xml' => $contenttypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $document,
        ]);
    }

    /**
     * Bytes of a zip archive that carries nothing OOXML specific.
     *
     * @return string
     */
    private function plain_zip_bytes(): string {
        return $this->zip_bytes(['readme.txt' => 'A plain zip, not an OOXML package.']);
    }

    /**
     * Build a zip archive in memory from a map of entry name to content.
     *
     * @param array $entries Entry name => entry content, in insertion order.
     * @return string Raw archive bytes.
     */
    private function zip_bytes(array $entries): string {
        $path = make_request_directory() . '/archive.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string)file_get_contents($path);
    }

    /**
     * An API service double that expects exactly one successful transfer.
     *
     * @return ai_course_api_service
     */
    private function mock_service_expecting_one_upload(): ai_course_api_service {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upload_syllabus'])
            ->getMock();
        $service->expects($this->once())->method('upload_syllabus')->willReturn(['ok' => true]);

        return $service;
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
