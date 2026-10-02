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

use local_coursegen\external\start_course_planning;
use local_coursegen\local\language_options;
use local_coursegen\tests\api_testcase;

/**
 * Language normalisation of the course planning endpoint.
 *
 * The language the client sends is normalised like the activity generation
 * endpoint does (language_options::resolve): a regional code reduces to its
 * base, an unsupported code falls back to the user's current language and,
 * when that one is not supported either, to the default code. The AI service
 * and the stored session therefore never receive an unsupported code.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_course_planning
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\start_course_planning::class)]
final class start_course_planning_test extends api_testcase {
    /**
     * Start a planning session with the given language and return the payload the service received.
     *
     * @param string|null $lang Language code sent by the client; null uses the parameter default.
     * @return array The payload passed to ai_course_api_service::start_course_planning().
     */
    private function start_and_capture_payload(?string $lang): array {
        $captured = null;
        $this->inject_api_service([
            'start_course_planning' => function (array $payload) use (&$captured): array {
                $captured = $payload;
                return ['thread_id' => 'thread-lang'];
            },
            'get_course_streaming_url' => 'https://ai.example.com/api/v1/course/stream/thread-lang',
        ]);

        $result = $lang === null
            ? start_course_planning::execute('Create a short course about volcanoes')
            : start_course_planning::execute('Create a short course about volcanoes', $lang);
        $this->resetDebugging();

        $this->assertTrue($result['success'], 'Planning must start: ' . ($result['message'] ?? ''));
        $this->assertIsArray($captured);

        return $captured;
    }

    /**
     * The language stored in the session created by the last planning start.
     *
     * @return string
     */
    private function stored_session_lang(): string {
        global $DB;

        $records = $DB->get_records('local_coursegen_course_sessions', [], 'id DESC', 'id, coursedata', 0, 1);
        $this->assertCount(1, $records);
        $coursedata = json_decode(reset($records)->coursedata, true);

        return (string)($coursedata['local_coursegen_lang'] ?? '');
    }

    /**
     * An unsupported code falls back to the user's current language when that one is supported.
     */
    public function test_unsupported_lang_falls_back_to_supported_current_language(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();
        $SESSION->lang = 'de';

        $payload = $this->start_and_capture_payload('ja');

        $this->assertSame('de', $payload['lang']);
        $this->assertSame('de', $this->stored_session_lang());
    }

    /**
     * An unsupported code with an unsupported current language falls back to the default code.
     */
    public function test_unsupported_lang_and_current_language_fall_back_to_default(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();
        $SESSION->lang = 'ja';

        $payload = $this->start_and_capture_payload('ja');

        $this->assertSame(language_options::DEFAULT_CODE, $payload['lang']);
        $this->assertSame('es', $payload['lang']);
        $this->assertSame('es', $this->stored_session_lang());
    }

    /**
     * A regional code reduces to its supported base code, whatever the case and separator.
     */
    public function test_regional_lang_is_reduced_to_base_code(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();
        $SESSION->lang = 'de';

        $payload = $this->start_and_capture_payload('PT-BR');

        $this->assertSame('pt', $payload['lang']);
        $this->assertSame('pt', $this->stored_session_lang());
    }

    /**
     * A supported code is sent as is, even when the current language differs.
     */
    public function test_supported_lang_is_kept(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();
        $SESSION->lang = 'de';

        $payload = $this->start_and_capture_payload('fr');

        $this->assertSame('fr', $payload['lang']);
        $this->assertSame('fr', $this->stored_session_lang());
    }

    /**
     * Without a language the parameter default is the plugin default code, which is
     * resolved like any other value (so a supported current language still wins).
     */
    public function test_parameter_default_is_the_default_code(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();

        $parameters = start_course_planning::execute_parameters();
        $this->assertSame(language_options::DEFAULT_CODE, $parameters->keys['lang']->default);

        $SESSION->lang = 'ja';
        $payload = $this->start_and_capture_payload(null);
        $this->assertSame(language_options::DEFAULT_CODE, $payload['lang']);
    }
}
