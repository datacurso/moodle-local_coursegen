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

use local_coursegen\local\streaming\sse_formatter;
use local_coursegen\local\streaming\sse_parser;

/**
 * Unit tests for the server-sent events formatter.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\sse_formatter
 */
final class sse_formatter_test extends \basic_testcase {
    /**
     * An event is written as an event line, a data line and a blank line.
     */
    public function test_event_block_layout(): void {
        $block = sse_formatter::event('message', '{"type":"status"}');

        $this->assertSame("event: message\ndata: {\"type\":\"status\"}\n\n", $block);
    }

    /**
     * An empty payload keeps its data line, as the done event needs.
     */
    public function test_empty_data_keeps_the_data_line(): void {
        $this->assertSame("event: done\ndata: \n\n", sse_formatter::event('done', ''));
    }

    /**
     * Every line of a multiline payload gets its own data field.
     *
     * @dataProvider multiline_provider
     * @param string $data Payload.
     * @param string $expected Expected block.
     */
    public function test_multiline_payload_is_split_in_data_lines(string $data, string $expected): void {
        $this->assertSame($expected, sse_formatter::event('message', $data));
    }

    /**
     * Payloads with line breaks.
     *
     * @return array
     */
    public static function multiline_provider(): array {
        return [
            'lf' => ["a\nb", "event: message\ndata: a\ndata: b\n\n"],
            'crlf' => ["a\r\nb", "event: message\ndata: a\ndata: b\n\n"],
            'cr' => ["a\rb", "event: message\ndata: a\ndata: b\n\n"],
            'trailing newline' => ["a\n", "event: message\ndata: a\ndata: \n\n"],
        ];
    }

    /**
     * An event name cannot inject extra fields through a line break.
     */
    public function test_event_name_cannot_inject_lines(): void {
        $block = sse_formatter::event("message\ndata: injected", 'x');

        $this->assertSame("event: messagedata: injected\ndata: x\n\n", $block);
    }

    /**
     * A comment is a single line that parsers ignore.
     */
    public function test_comment_block_layout(): void {
        $this->assertSame(": keepalive\n\n", sse_formatter::comment('keepalive'));
    }

    /**
     * A comment cannot smuggle a data field through a line break.
     */
    public function test_comment_cannot_inject_lines(): void {
        $this->assertSame(": pingdata: x\n\n", sse_formatter::comment("ping\ndata: x"));
    }

    /**
     * What the formatter writes is read back unchanged by the parser.
     */
    public function test_round_trip_through_the_parser(): void {
        $parser = new sse_parser();
        $stream = sse_formatter::event('message', '{"type":"token","text":"a\nb"}')
            . sse_formatter::comment('keepalive')
            . sse_formatter::event('done', '');

        $events = $parser->feed($stream);

        $this->assertSame([
            ['event' => 'message', 'data' => '{"type":"token","text":"a\nb"}'],
            ['event' => 'done', 'data' => ''],
        ], $events);
    }
}
