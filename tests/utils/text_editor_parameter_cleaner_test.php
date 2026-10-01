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

namespace local_coursegen\utils;

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Tests for the generated image download performed while cleaning editor text.
 *
 * The AI HTTP client is injected through api_client_factory, so no network
 * request is ever performed. The cleaner caches its client in a static for the
 * whole process, so each test runs isolated.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\text_editor_parameter_cleaner
 *
 * @runTestsInSeparateProcesses
 */
final class text_editor_parameter_cleaner_test extends \advanced_testcase {
    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Inject an ai_course_api mock whose download_file() records the endpoint
     * and returns a real draft file.
     *
     * @param array $endpoints Reference receiving every endpoint requested.
     * @return void
     */
    private function inject_download_client(array &$endpoints): void {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename, array $filerecord = []) use (&$endpoints): \stored_file {
                global $USER;

                $endpoints[] = $endpoint;
                $fs = get_file_storage();

                return $fs->create_file_from_string((object) [
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $filerecord['itemid'],
                    'filepath' => '/',
                    'filename' => $filename,
                ], 'png bytes');
            }
        );

        api_client_factory::set_test_client($mock);
    }

    /**
     * The generated image path travels percent-encoded (RFC 3986): a space is
     * %20, never '+', and '~' stays as is.
     */
    public function test_generated_image_download_endpoint_is_rawurlencoded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $endpoints = [];
        $this->inject_download_client($endpoints);

        $parameters = [
            'name' => 'Page',
            'introeditor' => [
                'text' => '<p><img src="/tmp/generated_images/my image~1.png" alt="Diagram"></p>',
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ],
        ];

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);

        $this->assertSame(['/files/download?path=%2Ftmp%2Fgenerated_images%2Fmy%20image~1.png'], $endpoints);
        $this->assertStringContainsString('src="@@PLUGINFILE@@/my image~1.png"', $cleaned['introeditor']['text']);
        $this->assertGreaterThan(0, $cleaned['introeditor']['itemid']);
    }
}
