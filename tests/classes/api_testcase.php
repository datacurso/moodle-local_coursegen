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

namespace local_coursegen\tests;

use aiprovider_datacurso\httpclient\ai_course_api;
use core\context\user;
use local_coursegen\h5p_package_fixture;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\ai_course_api_service;
use PHPUnit\Framework\MockObject\MockObject;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/h5p_package_fixture.php');

/**
 * Base test case for tests that talk to the Datacurso AI service.
 *
 * Both plugin seams are reset between tests: the HTTP client injected through
 * api_client_factory::set_test_client() and the service injected through
 * api_client_factory::set_test_service(). The helpers build the usual test
 * doubles so no network request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class api_testcase extends \advanced_testcase {
    /**
     * Remove the injected doubles between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_service(null);
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Inject (or remove) the ai_course_api_service double returned by the factory.
     *
     * @param ai_course_api_service|null $service Service double, or null to restore real construction.
     * @return void
     */
    protected function set_test_api_service(?ai_course_api_service $service): void {
        api_client_factory::set_test_service($service);
    }

    /**
     * Inject an ai_course_api_service mock stubbing the given methods.
     *
     * Each entry maps a service method name to its behaviour: a Closure is
     * invoked with the call arguments (willReturnCallback), a Throwable is
     * thrown (willThrowException) and any other value is returned as is.
     *
     * @param array $methods Map of method name => return value, Closure or Throwable.
     * @return MockObject The injected service mock.
     */
    protected function inject_api_service(array $methods): MockObject {
        $service = $this->getMockBuilder(ai_course_api_service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_keys($methods))
            ->getMock();

        foreach ($methods as $method => $behaviour) {
            $stub = $service->method($method);
            if ($behaviour instanceof \Throwable) {
                $stub->willThrowException($behaviour);
            } else if ($behaviour instanceof \Closure) {
                $stub->willReturnCallback($behaviour);
            } else {
                $stub->willReturn($behaviour);
            }
        }

        api_client_factory::set_test_service($service);

        return $service;
    }

    /**
     * Inject an ai_course_api mock whose download_file() records every call and returns a draft file.
     *
     * The returned stored_file lives in the current user draft area, as the
     * real ai_course_api::download_file() does; the draft item id comes from
     * the file record handed by the caller, or a fresh one when none is given.
     *
     * @param array $calls Reference receiving one ['endpoint', 'filename', 'filerecord'] entry per call.
     * @param string|null $content File bytes; a structurally valid .h5p package by default.
     * @param bool $storefile When false no file is created and download_file() returns null.
     * @return MockObject The injected client mock.
     */
    protected function inject_download_client(array &$calls = [], ?string $content = null, bool $storefile = true): MockObject {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();

        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename, array $filerecord = []) use (
                &$calls,
                $content,
                $storefile
            ): ?\stored_file {
                $calls[] = ['endpoint' => $endpoint, 'filename' => $filename, 'filerecord' => $filerecord];
                if (!$storefile) {
                    return null;
                }
                return $this->create_draft_file($filename, $content, (int) ($filerecord['itemid'] ?? 0));
            }
        );

        api_client_factory::set_test_client($mock);

        return $mock;
    }

    /**
     * Create a real stored_file in the current user's draft area.
     *
     * @param string $filename File name.
     * @param string|null $content File bytes; a structurally valid .h5p package by default.
     * @param int $itemid Draft item id; a fresh one when 0.
     * @return \stored_file
     */
    protected function create_draft_file(string $filename, ?string $content = null, int $itemid = 0): \stored_file {
        global $USER;

        $record = (object) [
            'contextid' => user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid > 0 ? $itemid : file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ];

        return get_file_storage()->create_file_from_string($record, $content ?? h5p_package_fixture::bytes());
    }

    /**
     * Make the given course the current one.
     *
     * The module edit form resolves section info through the global $COURSE.
     * In a web request require_login() binds the page (and $COURSE) to the
     * course; without it, the theme initialisation triggered by the form
     * falls back to the site course and the target section cannot resolve.
     *
     * @param \stdClass $course Course record.
     * @return void
     */
    protected function set_current_course(\stdClass $course): void {
        global $PAGE;
        $PAGE->set_course($course);
    }

    /**
     * Build an AI activity result payload for an H5P activity, as returned by the service.
     *
     * @param array $paramoverrides Overrides merged into the parameters section.
     * @param array $modsettingsoverrides Overrides merged into mod_settings.
     * @return array Result info payload.
     */
    protected function h5p_activity_result(array $paramoverrides = [], array $modsettingsoverrides = []): array {
        $modsettings = array_merge([
            'file_path' => 'generated/packages/sample-activity.h5p',
            'file_name' => 'sample-activity.h5p',
        ], $modsettingsoverrides);

        $parameters = array_merge([
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
            'mod_settings' => $modsettings,
        ], $paramoverrides);

        return [
            'resource_type' => 'h5pactivity',
            'parameters' => $parameters,
        ];
    }
}
