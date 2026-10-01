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
use core\context\course;
use core\exception\moodle_exception;
use core\exception\require_login_exception;
use core\exception\required_capability_exception;
use local_coursegen\external\activity_feedback;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\module_job_service;

/**
 * Permission and error contract of the activity feedback web service.
 *
 * Like create_mod and create_mod_stream, the endpoint must validate the
 * course context (so a user who cannot access the course is rejected by the
 * login check) and enforce the activity generation capabilities in it. When
 * the AI service fails, the technical detail must travel as the exception's
 * debug information, never as the $a placeholder of a string that has none.
 *
 * The AI HTTP client is mocked through api_client_factory, so no network
 * request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_feedback
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\activity_feedback::class)]
final class activity_feedback_permissions_test extends \advanced_testcase {
    /** @var string Technical marker that must stay in the debug information. */
    private const TECHNICALDETAIL = 'TECH-SECRET curl error 500 at https://internal-api.invalid/activity/feedback';

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Inject an ai_course_api mock whose request() behaves as given.
     *
     * @param callable $request Callback receiving (method, path, body).
     * @return void
     */
    private function inject_client(callable $request): void {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $mock->method('request')->willReturnCallback($request);

        api_client_factory::set_test_client($mock);
    }

    /**
     * A user who cannot access the course is rejected by the context validation.
     */
    public function test_user_without_course_access_is_rejected_by_login_check(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        module_job_service::create_job($course->id, $outsider->id, 'job-outsider', 0, null, null, 1, null, 'completed');

        $this->expectException(require_login_exception::class);
        activity_feedback::execute($course->id, 'job-outsider', 'accept');
    }

    /**
     * An enrolled user without the generation capabilities is rejected.
     */
    public function test_user_without_capability_is_rejected(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        module_job_service::create_job($course->id, $student->id, 'job-student', 0, null, null, 1, null, 'completed');

        $this->expectException(required_capability_exception::class);
        activity_feedback::execute($course->id, 'job-student', 'accept');
    }

    /**
     * A teacher's feedback is validated in the course context and reaches the service.
     */
    public function test_teacher_feedback_is_sent_in_course_context(): void {
        global $PAGE;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        module_job_service::create_job($course->id, $teacher->id, 'job-teacher', 0, null, null, 1, null, 'completed');

        $captured = null;
        $this->inject_client(function (string $method, string $path, array $body = []) use (&$captured): array {
            $captured = ['method' => $method, 'path' => $path, 'body' => $body];
            return ['action' => 'adjust'];
        });

        $result = activity_feedback::execute($course->id, 'job-teacher', 'adjust', 'Make it shorter');

        $this->assertTrue($result['success']);
        $this->assertSame('adjust', $result['action']);
        $this->assertSame(course::instance($course->id)->id, $PAGE->context->id);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('/activity/feedback', $captured['path']);
        $this->assertSame('job-teacher', $captured['body']['thread_id']);
        $this->assertSame('Make it shorter', $captured['body']['instruction']);
    }

    /**
     * A service failure keeps the technical detail as debug information and
     * leaves the localized message untouched.
     *
     * Under PHPUnit moodle_exception appends the debug information to
     * getMessage(), so the user-facing text is checked through the string
     * the exception resolves to, with no $a placeholder abuse.
     */
    public function test_service_error_keeps_technical_detail_in_debuginfo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        module_job_service::create_job($course->id, get_admin()->id, 'job-fail', 0, null, null, 1, null, 'completed');
        $this->inject_client(function (): array {
            throw new moodle_exception('generalexceptionmessage', 'error', '', self::TECHNICALDETAIL);
        });

        try {
            activity_feedback::execute($course->id, 'job-fail', 'accept');
            $this->fail('A service failure must raise a moodle_exception.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_sending_feedback', $e->errorcode);
            $this->assertNull($e->a, 'The technical detail must not be passed as the $a placeholder.');
            $this->assertStringContainsString(self::TECHNICALDETAIL, (string)$e->debuginfo);
            $this->assertStringNotContainsString(
                self::TECHNICALDETAIL,
                get_string($e->errorcode, $e->module, $e->a),
                'The user-facing message must stay the localized string.'
            );
        }
    }
}
