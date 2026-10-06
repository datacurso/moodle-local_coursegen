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
use local_coursegen\local\streaming\stream_type;

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
     * The browser gets the relay of the plugin for a course session, not the service URL.
     */
    public function test_course_streaming_url_is_the_relay(): void {
        global $CFG;

        $url = $this->service()->get_course_streaming_url('thread-1');

        $this->assertSame($CFG->wwwroot . '/local/coursegen/stream.php?streamtype=course&threadid=thread-1', $url);
        $this->assertStringNotContainsString('ai.example.com', $url);
    }

    /**
     * The browser gets the relay of the plugin for an activity job, not the service URL.
     */
    public function test_activity_streaming_url_is_the_relay(): void {
        global $CFG;

        $url = $this->service()->get_mod_streaming_url_for_job('job-1');

        $this->assertSame($CFG->wwwroot . '/local/coursegen/stream.php?streamtype=activity&threadid=job-1', $url);
        $this->assertStringNotContainsString('ai.example.com', $url);
    }

    /**
     * The relay reads the course stream from the service.
     */
    public function test_upstream_url_of_a_course_stream(): void {
        $url = $this->service()->get_upstream_stream_url(stream_type::COURSE, 'thread-1');

        $this->assertSame('https://ai.example.com/api/v1/course/stream/thread-1', $url);
    }

    /**
     * The relay reads the activity stream from the service.
     */
    public function test_upstream_url_of_an_activity_stream(): void {
        $url = $this->service()->get_upstream_stream_url(stream_type::ACTIVITY, 'job-1');

        $this->assertSame('https://ai.example.com/api/v1/activity/stream/job-1', $url);
    }

    /**
     * The relay reads the stream of the template agent from the service.
     */
    public function test_upstream_url_of_a_template_stream(): void {
        $url = $this->service()->get_upstream_stream_url(stream_type::TEMPLATE, 'thread-3');

        $this->assertSame('https://ai.example.com/api/v1/template-agent/stream/thread-3', $url);
    }

    /**
     * A stream type that does not exist has no service URL.
     */
    public function test_upstream_url_rejects_an_unknown_stream_type(): void {
        $this->expectException(\coding_exception::class);

        $this->service()->get_upstream_stream_url('unknown', 'thread-1');
    }

    /**
     * The license header is the one the provider client builds, so there is a single place that builds it.
     */
    public function test_license_header_comes_from_the_provider_client(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->once())->method('get_license_header')->willReturn('License-Key: key-abc');

        $service = new ai_course_api_service($client);

        $this->assertSame('License-Key: key-abc', $service->get_license_header());
    }

    /**
     * Without a configured key the provider client refuses, and the refusal reaches the caller untouched.
     */
    public function test_license_header_fails_when_the_client_has_no_key(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->method('get_license_header')->willThrowException(
            new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso')
        );

        $service = new ai_course_api_service($client);

        try {
            $service->get_license_header();
            $this->fail('A missing key must stop the call.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidlicensekey', $e->errorcode);
        }
    }
}
