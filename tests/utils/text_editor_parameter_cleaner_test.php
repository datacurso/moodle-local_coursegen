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
    }
}
