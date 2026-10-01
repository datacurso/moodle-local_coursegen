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
use local_coursegen\local\warning_collector;
use local_coursegen\tests\api_testcase;

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
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\utils\text_editor_parameter_cleaner::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class text_editor_parameter_cleaner_test extends api_testcase {
    /**
     * The generated image path travels percent-encoded (RFC 3986): a space is
     * %20, never '+', and '~' stays as is.
     */
    public function test_generated_image_download_endpoint_is_rawurlencoded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'png bytes');

        $parameters = [
            'name' => 'Page',
            'introeditor' => [
                'text' => '<p><img src="/tmp/generated_images/my image~1.png" alt="Diagram"></p>',
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ],
        ];

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);

        $this->assertSame(
            ['/files/download?path=%2Ftmp%2Fgenerated_images%2Fmy%20image~1.png'],
            array_column($calls, 'endpoint')
        );
        $this->assertStringContainsString('src="@@PLUGINFILE@@/my image~1.png"', $cleaned['introeditor']['text']);
        $this->assertGreaterThan(0, $cleaned['introeditor']['itemid']);
        $this->assertSame([], warning_collector::drain());
    }

    /**
     * A failed download leaves the reference untouched and records an image_download warning
     * naming the file.
     */
    public function test_failed_image_download_is_recorded_as_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willThrowException(new \RuntimeException('curl error at https://internal.invalid'));
        api_client_factory::set_test_client($mock);

        $parameters = ['introeditor' => [
            'text' => '<p><img src="/tmp/generated_images/diagram.png" alt="Diagram"></p>',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ]];

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $this->assertStringContainsString('src="/tmp/generated_images/diagram.png"', $cleaned['introeditor']['text']);
        $warnings = warning_collector::drain();
        $this->assertCount(1, $warnings);
        $this->assertSame(warning_collector::STEP_IMAGE_DOWNLOAD, $warnings[0]['step']);
        $this->assertSame('diagram.png', $warnings[0]['subject']);
        $messages = warning_collector::to_messages($warnings);
        $this->assertSame([get_string('generationwarning_image_download', 'local_coursegen', 'diagram.png')], $messages);
        $this->assertStringNotContainsString('internal.invalid', $messages[0]);
    }

    /**
     * A failed client initialisation is not cached: every image reports the client_init warning,
     * and once the client can be built the images are downloaded again.
     */
    public function test_failed_client_initialisation_is_not_cached(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'png bytes');
        warning_collector::set_test_failure(warning_collector::STEP_CLIENT_INIT, new \RuntimeException('no client'));

        $parameters = ['introeditor' => [
            'text' => '<p><img src="/tmp/generated_images/one.png"><img src="/tmp/generated_images/two.png"></p>',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ]];

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);
        $this->assertDebuggingCalledCount(2);

        $this->assertCount(0, $calls, 'Without a client nothing is downloaded.');
        $this->assertStringContainsString('/tmp/generated_images/one.png', $cleaned['introeditor']['text']);
        $steps = array_column(warning_collector::drain(), 'step');
        $this->assertSame([warning_collector::STEP_CLIENT_INIT, warning_collector::STEP_CLIENT_INIT], $steps);

        // The failure was not cached: once the client can be built, images are downloaded.
        warning_collector::clear_test_failures();
        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);
        $this->assertDebuggingNotCalled();
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('@@PLUGINFILE@@/one.png', $cleaned['introeditor']['text']);
        $this->assertSame([], warning_collector::drain());
    }
}
