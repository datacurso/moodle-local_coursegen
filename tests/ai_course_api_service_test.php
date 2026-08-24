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

/**
 * Tests for the course streaming URL composition of the AI course API service.
 *
 * The HTTP client is replaced through the api_client_factory seam, so no
 * network request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\ai_course_api_service
 */
final class ai_course_api_service_test extends \advanced_testcase {
    /**
     * Always remove the injected factory test double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Inject an ai_course_api mock resolving to the given base URL.
     *
     * @param string $baseurl Base URL reported by the mocked client.
     * @return void
     */
    private function inject_client(string $baseurl): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_base_url'])
            ->getMock();
        $client->method('get_base_url')->willReturn(rtrim($baseurl, '/') . '/');

        api_client_factory::set_test_client($client);
    }

    /**
     * MDL-UNIT-001: The course stream URL is composed from the service base URL
     * and the external thread id of the session.
     */
    public function test_course_stream_url_composed_from_base_and_thread(): void {
        $this->resetAfterTest();

        $this->inject_client('https://ai.example.com/api/v1');

        $service = new ai_course_api_service();
        $url = $service->get_course_streaming_url('sess-123');

        $this->assertSame('https://ai.example.com/api/v1/course/stream/sess-123', $url);
    }

    /**
     * MDL-UNIT-001: The development URL overrides configured in the plugin are
     * handed to the client construction and reflected in the resulting URL.
     */
    public function test_dev_url_overrides_reflected_in_stream_url(): void {
        $this->resetAfterTest();

        set_config('datacurso_service_url', 'https://dev.example.com/api/v1', 'local_coursegen');
        set_config('datacurso_service_url_eu', 'https://dev-eu.example.com/api/v1', 'local_coursegen');

        $this->inject_client('https://dev.example.com/api/v1');

        $service = new ai_course_api_service();
        $url = $service->get_course_streaming_url('sess-9');

        // The configured overrides reach the client construction untouched.
        $lasturls = api_client_factory::get_last_urls();
        $this->assertSame('https://dev.example.com/api/v1', $lasturls['baseurl']);
        $this->assertSame('https://dev-eu.example.com/api/v1', $lasturls['baseurleu']);

        // And the stream URL points at the overridden service for the thread.
        $this->assertSame('https://dev.example.com/api/v1/course/stream/sess-9', $url);
    }
}
