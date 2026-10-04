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

use local_coursegen\local\service\streaming_url_builder;
use local_coursegen\local\streaming\stream_kind;

/**
 * Unit tests for the streaming URL builder.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\streaming_url_builder
 */
final class streaming_url_builder_test extends \basic_testcase {
    /**
     * Course planning stream URL is built from the base URL and session id.
     */
    public function test_course_stream_url(): void {
        $url = streaming_url_builder::course_stream('https://ai.example.com/api/v1/', 'sess-123');

        $this->assertSame('https://ai.example.com/api/v1/course/stream/sess-123', $url);
    }

    /**
     * Activity (mod) stream URL is built from the base URL and job id.
     */
    public function test_mod_stream_url(): void {
        $url = streaming_url_builder::mod_stream('https://ai.example.com/api/v1/', 'job-9');

        $this->assertSame('https://ai.example.com/api/v1/activity/stream/job-9', $url);
    }

    /**
     * A base URL without a trailing slash produces the same result.
     */
    public function test_base_url_without_trailing_slash(): void {
        $url = streaming_url_builder::course_stream('https://ai.example.com/api/v1', 'sess-123');

        $this->assertSame('https://ai.example.com/api/v1/course/stream/sess-123', $url);
    }

    /**
     * Identifiers are URL-encoded to keep the path safe.
     */
    public function test_identifiers_are_url_encoded(): void {
        $url = streaming_url_builder::mod_stream('https://ai.example.com/api/v1/', 'job/9 x');

        $this->assertSame('https://ai.example.com/api/v1/activity/stream/job%2F9+x', $url);
    }

    /**
     * The relay URL points to the plugin page with the kind and the thread.
     */
    public function test_relay_url_points_to_the_plugin_page(): void {
        global $CFG;

        $url = streaming_url_builder::relay(stream_kind::COURSE, 'thread-1');

        $this->assertSame($CFG->wwwroot . '/local/coursegen/stream.php?kind=course&id=thread-1', $url);
    }

    /**
     * The relay URL of an activity job carries the activity kind.
     */
    public function test_relay_url_for_an_activity_job(): void {
        global $CFG;

        $url = streaming_url_builder::relay(stream_kind::ACTIVITY, 'job-9');

        $this->assertSame($CFG->wwwroot . '/local/coursegen/stream.php?kind=activity&id=job-9', $url);
    }

    /**
     * The relay URL never exposes a service URL.
     */
    public function test_relay_url_does_not_expose_the_service(): void {
        $url = streaming_url_builder::relay(stream_kind::COURSE, 'thread-1');

        $this->assertStringNotContainsString('/api/v1/', $url);
        $this->assertStringNotContainsString('/course/stream/', $url);
    }

    /**
     * Special characters of the identifier are encoded in the query string.
     */
    public function test_relay_url_encodes_the_identifier(): void {
        $url = streaming_url_builder::relay(stream_kind::COURSE, 'a b&c');

        $this->assertStringContainsString('id=a+b%26c', $url);
    }

    /**
     * A kind that does not exist cannot be turned into a URL.
     */
    public function test_relay_url_rejects_an_unknown_kind(): void {
        $this->expectException(\coding_exception::class);

        streaming_url_builder::relay('template', 'thread-1');
    }
}
