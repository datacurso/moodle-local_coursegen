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

/**
 * Permission tests for creating the H5P activity through the generator.
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
 *
 * @runTestsInSeparateProcesses
 */
final class create_mod_permissions_test extends \advanced_testcase {

    /**
     * Load the testable subclass in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_create_mod.php');
        require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');

        // external_api::validate_context() resets the page to the site course,
        // so the module edit form resolves section info against the front page.
        // Give the front page the section rows a real site has.
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

    /**
     * MDL-INT-007: A user with course management permissions can create the H5P
     * activity through the generator.
     */
    public function test_user_with_manageactivities_can_create_activity(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        module_job_service::create_job($course->id, $teacher->id, 'job-ok', 0, null, null, 1, null, 'completed');
        $this->inject_api_service($this->h5p_activity_result());
        $this->inject_download_client();

        $result = testable_create_mod::execute($course->id, 1, 'job-ok');
        // One pre-existing developer notice: execute_parameters() declares
        // top-level VALUE_OPTIONAL values instead of VALUE_DEFAULT.
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame('h5pactivity', $result['data']['modname']);

        $records = $DB->get_records('h5pactivity', ['course' => $course->id]);
        $this->assertCount(1, $records);
        $this->assertSame('AI generated H5P', reset($records)->name);
    }

    /**
     * MDL-INT-007: A user without activity management permissions receives a
     * clear permission error and nothing is created.
     */
    public function test_user_without_manageactivities_cannot_create_activity(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        module_job_service::create_job($course->id, $student->id, 'job-denied', 0, null, null, 1, null, 'completed');
        $this->inject_api_service($this->h5p_activity_result());
        $this->inject_download_client();

        $result = testable_create_mod::execute($course->id, 1, 'job-denied');
        // One pre-existing developer notice from execute_parameters() plus the
        // debugging call from the permission failure handler.
        $this->assertDebuggingCalledCount(2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            get_string('nopermissions', 'error', get_capability_string('moodle/course:manageactivities')),
            $result['message']
        );

        // No residue: nothing was created in the course.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity', ['course' => $course->id]));
    }
}
