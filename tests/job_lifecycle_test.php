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
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * Job lifecycle tests: a generation job is single-use.
 *
 * The AI service and the download HTTP client are both mocked, so no network
 * request is ever performed. The testable subclass fixture loads
 * lib/externallib.php (through create_mod), which requires each test to run
 * in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_mod
 * @covers     \local_coursegen\local\service\module_job_service
 *
 * @runTestsInSeparateProcesses
 */
final class job_lifecycle_test extends \advanced_testcase {
    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_create_mod.php');
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
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * A successful creation marks the job consumed and a replay is rejected
     * with the localized error before touching the AI service again.
     */
    public function test_consumed_job_cannot_be_replayed(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = module_job_service::create_job($course->id, $USER->id, 'job-once', 0, null, null, 1, null, 'completed');
        $this->inject_api_service($this->h5p_activity_result());
        $this->inject_download_client();

        $result = testable_create_mod::execute($course->id, 1, 'job-once');
        $this->resetDebugging();
        $this->assertTrue($result['ok'], 'First creation must succeed: ' . ($result['message'] ?? ''));

        // The job is marked consumed after the module is created.
        $this->assertSame(
            module_job_service::STATUS_CONSUMED,
            $DB->get_field('local_coursegen_module_jobs', 'status', ['id' => $job->get('id')])
        );

        // Replaying the same job must be rejected with the localized error.
        try {
            testable_create_mod::execute($course->id, 1, 'job-once');
            $this->fail('A moodle_exception was expected for the replayed job.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_job_already_used', $e->errorcode);
            $this->assertStringContainsString(
                get_string('error_job_already_used', 'local_coursegen'),
                $e->getMessage()
            );
        }
        $this->resetDebugging();

        // The replay created nothing: still exactly one module in the course.
        $this->assertSame(1, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * The consumed status constant and update_status() transition work as a unit.
     */
    public function test_update_status_marks_job_consumed(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = module_job_service::create_job($course->id, $USER->id, 'job-status', 0, null, null, 1, null, 'completed');

        $this->assertSame('consumed', module_job_service::STATUS_CONSUMED);

        module_job_service::update_status((int)$job->get('id'), module_job_service::STATUS_CONSUMED);

        $reloaded = module_job_service::get_user_job('job-status', $course->id, (int)$USER->id);
        $this->assertSame(module_job_service::STATUS_CONSUMED, $reloaded->get('status'));
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
