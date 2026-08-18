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
}
