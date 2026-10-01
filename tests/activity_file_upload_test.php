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

use core\exception\moodle_exception;
use core\exception\require_login_exception;
use core\exception\required_capability_exception;
use local_coursegen\external\activity_file_upload;
use local_coursegen\tests\api_testcase;

/**
 * Tests for the activity file upload web service.
 *
 * The AI service is mocked through the factory seam, so no network request
 * is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_file_upload
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\activity_file_upload::class)]
final class activity_file_upload_test extends api_testcase {
    /**
     * Create a course with an enrolled user of the given role and log that user in.
     *
     * @param string $role Role shortname to enrol with.
     * @return \stdClass The course.
     */
    private function create_course_as(string $role): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, $role);
        $this->setUser($user);

        return $course;
    }

    /**
     * Create a generation job of the current user in the course.
     *
     * @param \stdClass $course Course.
     * @param string $jobid External job id.
     * @return void
     */
    private function create_job(\stdClass $course, string $jobid): void {
        $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_module_job([
            'courseid' => $course->id,
            'job_id' => $jobid,
        ]);
    }

    /**
     * A logged-out caller is rejected by the context validation.
     */
    public function test_not_logged_in_user_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser(null);

        $this->expectException(require_login_exception::class);
        activity_file_upload::execute($course->id, 'job-1', file_get_unused_draft_itemid());
    }

    /**
     * An enrolled student lacks the activity generation capabilities.
     */
    public function test_student_without_capability_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('student');
        $this->create_job($course, 'job-student');

        $this->expectException(required_capability_exception::class);
        activity_file_upload::execute($course->id, 'job-student', file_get_unused_draft_itemid());
    }

    /**
     * A job that does not belong to the current user and course is rejected.
     */
    public function test_missing_job_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');

        try {
            activity_file_upload::execute($course->id, 'job-missing', file_get_unused_draft_itemid());
            $this->fail('A missing job must be rejected with a moodle_exception.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_no_module_job_found', $e->errorcode);
            $this->assertSame('local_coursegen', $e->module);
        }
    }

    /**
     * An empty draft area is rejected before contacting the AI service.
     */
    public function test_empty_draft_area_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->create_job($course, 'job-empty');
        $uploaded = false;
        $this->inject_api_service([
            'upload_activity_file' => function () use (&$uploaded): array {
                $uploaded = true;
                return ['ok' => true];
            },
        ]);

        try {
            activity_file_upload::execute($course->id, 'job-empty', file_get_unused_draft_itemid());
            $this->fail('An empty draft area must be rejected with a moodle_exception.');
        } catch (moodle_exception $e) {
            $this->assertSame('nofile', $e->errorcode);
        }
        $this->assertFalse($uploaded, 'The AI service must not be called without a file.');
    }

    /**
     * The draft file is handed to the AI service for the job thread.
     */
    public function test_draft_file_is_uploaded_to_the_job(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->create_job($course, 'job-upload');
        $draftitemid = file_get_unused_draft_itemid();
        $this->create_draft_file('statement.pdf', '%PDF-1.4 statement', $draftitemid);

        $captured = null;
        $this->inject_api_service([
            'upload_activity_file' => function (string $threadid, \stored_file $file) use (&$captured): array {
                $captured = ['threadid' => $threadid, 'filename' => $file->get_filename(), 'content' => $file->get_content()];
                return ['ok' => true];
            },
        ]);

        $result = activity_file_upload::execute($course->id, 'job-upload', $draftitemid);

        $this->assertTrue($result['success']);
        $this->assertSame(get_string('activity_file_uploaded', 'local_coursegen'), $result['message']);
        $this->assertSame('job-upload', $captured['threadid']);
        $this->assertSame('statement.pdf', $captured['filename']);
        $this->assertSame('%PDF-1.4 statement', $captured['content']);
    }

    /**
     * A service failure surfaces the localized error and keeps the technical
     * detail as exception debug information, never in the message.
     */
    public function test_service_failure_keeps_detail_in_debuginfo(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->create_job($course, 'job-fail');
        $draftitemid = file_get_unused_draft_itemid();
        $this->create_draft_file('statement.pdf', '%PDF-1.4 statement', $draftitemid);

        $this->inject_api_service([
            'upload_activity_file' => new moodle_exception('curlerror', 'aiprovider_datacurso', '', '', 'TECH-DETAIL-42'),
        ]);

        try {
            activity_file_upload::execute($course->id, 'job-fail', $draftitemid);
            $this->fail('A service failure must be rethrown as a localized moodle_exception.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_sending_activity_file', $e->errorcode);
            $this->assertSame('local_coursegen', $e->module);
            $this->assertNull($e->a, 'The technical detail must not be passed as the $a placeholder.');
            $this->assertStringContainsString('TECH-DETAIL-42', (string)$e->debuginfo);
            // The user-facing string carries no detail (getMessage() appends debuginfo under PHPUnit).
            $this->assertStringNotContainsString('TECH-DETAIL-42', get_string($e->errorcode, $e->module, $e->a));
        }
    }

    /**
     * When the service exception carries no debug information its message becomes the detail.
     */
    public function test_service_failure_without_debuginfo_uses_message_as_detail(): void {
        $this->resetAfterTest();

        $course = $this->create_course_as('editingteacher');
        $this->create_job($course, 'job-fail-msg');
        $draftitemid = file_get_unused_draft_itemid();
        $this->create_draft_file('statement.pdf', '%PDF-1.4 statement', $draftitemid);

        $cause = new moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused');
        $this->inject_api_service(['upload_activity_file' => $cause]);

        try {
            activity_file_upload::execute($course->id, 'job-fail-msg', $draftitemid);
            $this->fail('A service failure must be rethrown as a localized moodle_exception.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_sending_activity_file', $e->errorcode);
            $this->assertNull($e->a, 'The technical detail must not be passed as the $a placeholder.');
            $this->assertStringContainsString($cause->getMessage(), (string)$e->debuginfo);
            $this->assertStringNotContainsString('Connection refused', get_string($e->errorcode, $e->module, $e->a));
        }
    }
}
