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
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\streaming\stream_kind;

/**
 * Unit tests for the stream URLs given by the AI course API service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\ai_course_api_service
 */
final class ai_course_api_service_streaming_test extends \advanced_testcase {
    /**
     * Build the service on a client that answers a fixed base URL.
     *
     * @return ai_course_api_service
     */
    private function service(): ai_course_api_service {
        $client = $this->createMock(ai_course_api::class);
        $client->method('get_base_url')->willReturn('https://ai.example.com/api/v1/');

        return new ai_course_api_service($client);
    }

    /**
     * The relay reads the course stream from the service.
     */
    public function test_upstream_url_of_a_course_stream(): void {
        $url = $this->service()->get_upstream_stream_url(stream_kind::COURSE, 'thread-1');

        $this->assertSame('https://ai.example.com/api/v1/course/stream/thread-1', $url);
    }

    /**
     * The relay reads the activity stream from the service.
     */
    public function test_upstream_url_of_an_activity_stream(): void {
        $url = $this->service()->get_upstream_stream_url(stream_kind::ACTIVITY, 'job-1');

        $this->assertSame('https://ai.example.com/api/v1/activity/stream/job-1', $url);
    }

    /**
     * A kind that does not exist has no service URL.
     */
    public function test_upstream_url_rejects_an_unknown_kind(): void {
        $this->expectException(\coding_exception::class);

        $this->service()->get_upstream_stream_url('template', 'thread-1');
    }

    /**
     * The license key is the one configured in the provider plugin.
     */
    public function test_license_key_comes_from_the_provider_configuration(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'key-abc', 'aiprovider_datacurso');

        $this->assertSame('key-abc', $this->service()->get_license_key());
    }

    /**
     * Without a configured key the license key is an empty string.
     */
    public function test_license_key_is_empty_when_not_configured(): void {
        $this->resetAfterTest();
        unset_config('licensekey', 'aiprovider_datacurso');

        $this->assertSame('', $this->service()->get_license_key());
    }
}
