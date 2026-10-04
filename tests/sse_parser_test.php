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

use local_coursegen\local\streaming\sse_parser;

/**
 * Unit tests for the server-sent events parser.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\sse_parser
 */
final class sse_parser_test extends \basic_testcase {
    /**
     * A block ending in a blank line is returned as one event with the default name.
     */
    public function test_data_block_uses_default_event_name(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data: {\"type\":\"status\"}\n\n");

        $this->assertSame([['event' => 'message', 'data' => '{"type":"status"}']], $events);
    }

    /**
     * The event field names the event.
     */
    public function test_event_field_names_the_event(): void {
        $parser = new sse_parser();

        $events = $parser->feed("event: message\ndata: hello\n\n");

        $this->assertSame([['event' => 'message', 'data' => 'hello']], $events);
    }

    /**
     * A done event with an empty data line is surfaced with empty data.
     */
    public function test_done_event_with_empty_data_is_returned(): void {
        $parser = new sse_parser();

        $events = $parser->feed("event: done\ndata:\n\n");

        $this->assertSame([['event' => 'done', 'data' => '']], $events);
    }

    /**
     * A done event without any data line is still returned.
     */
    public function test_event_without_data_line_is_returned(): void {
        $parser = new sse_parser();

        $events = $parser->feed("event: done\n\n");

        $this->assertSame([['event' => 'done', 'data' => '']], $events);
    }

    /**
     * Several data lines of one event are joined with a newline.
     */
    public function test_multiple_data_lines_are_joined(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data: first\ndata: second\ndata: third\n\n");

        $this->assertSame([['event' => 'message', 'data' => "first\nsecond\nthird"]], $events);
    }

    /**
     * Every supported line terminator separates events the same way.
     *
     * @dataProvider line_terminator_provider
     * @param string $terminator Line terminator used by the stream.
     */
    public function test_line_terminators(string $terminator): void {
        $parser = new sse_parser();
        $stream = 'event: message' . $terminator . 'data: one' . $terminator . $terminator
            . 'event: done' . $terminator . 'data:' . $terminator . $terminator;

        // The next field starts after the stream, which settles a CR that ends the last blank line.
        $events = $parser->feed($stream . ':');

        $this->assertSame([
            ['event' => 'message', 'data' => 'one'],
            ['event' => 'done', 'data' => ''],
        ], $events);
    }

    /**
     * Terminators accepted by the format.
     *
     * @return array
     */
    public static function line_terminator_provider(): array {
        return [
            'lf' => ["\n"],
            'crlf' => ["\r\n"],
            'cr' => ["\r"],
        ];
    }

    /**
     * Comment lines such as the pings of the service never produce an event.
     */
    public function test_comment_lines_are_ignored(): void {
        $parser = new sse_parser();

        $events = $parser->feed(": ping\n\n: another ping\ndata: kept\n\n");

        $this->assertSame([['event' => 'message', 'data' => 'kept']], $events);
    }

    /**
     * A block that only holds a comment is not an event.
     */
    public function test_comment_only_block_returns_nothing(): void {
        $parser = new sse_parser();

        $this->assertSame([], $parser->feed(": ping\r\n\r\n"));
    }

    /**
     * An event split across chunks is returned once the blank line arrives.
     */
    public function test_event_split_across_chunks(): void {
        $parser = new sse_parser();

        $this->assertSame([], $parser->feed('event: mess'));
        $this->assertSame([], $parser->feed("age\ndata: {\"a\":"));
        $this->assertSame([], $parser->feed("1}\n"));
        $events = $parser->feed("\n");

        $this->assertSame([['event' => 'message', 'data' => '{"a":1}']], $events);
    }

    /**
     * A CRLF pair split between two chunks is one terminator, not two.
     */
    public function test_crlf_split_between_chunks_is_one_terminator(): void {
        $parser = new sse_parser();

        $first = $parser->feed("data: one\r");
        $second = $parser->feed("\n\r");
        $third = $parser->feed("\n");

        $this->assertSame([], $first);
        $this->assertSame([], $second);
        $this->assertSame([['event' => 'message', 'data' => 'one']], $third);
    }

    /**
     * Several events in one chunk are returned in order.
     */
    public function test_several_events_in_one_chunk_keep_their_order(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data: a\n\ndata: b\n\ndata: c\n\n");

        $this->assertSame(['a', 'b', 'c'], array_column($events, 'data'));
    }

    /**
     * Only one leading space after the colon is removed.
     */
    public function test_only_one_leading_space_is_removed(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data:  two spaces\n\ndata:nospace\n\n");

        $this->assertSame(' two spaces', $events[0]['data']);
        $this->assertSame('nospace', $events[1]['data']);
    }

    /**
     * A field line without a colon is a field with an empty value.
     */
    public function test_field_without_colon_has_empty_value(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data\n\n");

        $this->assertSame([['event' => 'message', 'data' => '']], $events);
    }

    /**
     * Unknown fields, ids and retry hints are ignored.
     */
    public function test_unknown_fields_are_ignored(): void {
        $parser = new sse_parser();

        $events = $parser->feed("id: 7\nretry: 3000\nfoo: bar\ndata: x\n\n");

        $this->assertSame([['event' => 'message', 'data' => 'x']], $events);
    }

    /**
     * The event name does not leak into the next event.
     */
    public function test_event_name_resets_after_dispatch(): void {
        $parser = new sse_parser();

        $events = $parser->feed("event: done\ndata:\n\ndata: next\n\n");

        $this->assertSame('done', $events[0]['event']);
        $this->assertSame('message', $events[1]['event']);
    }

    /**
     * Blank lines with nothing pending produce no events.
     */
    public function test_stray_blank_lines_produce_nothing(): void {
        $parser = new sse_parser();

        $this->assertSame([], $parser->feed("\n\n\r\n"));
    }

    /**
     * A byte order mark at the start of the stream is skipped.
     */
    public function test_leading_byte_order_mark_is_skipped(): void {
        $parser = new sse_parser();

        $events = $parser->feed("\xEF\xBB\xBFdata: hi\n\n");

        $this->assertSame([['event' => 'message', 'data' => 'hi']], $events);
    }

    /**
     * An event cut off by the end of the stream is never returned.
     */
    public function test_incomplete_event_is_not_returned(): void {
        $parser = new sse_parser();

        $events = $parser->feed("data: {\"type\":\"tok");

        $this->assertSame([], $events);
        $this->assertTrue($parser->has_pending());
    }

    /**
     * After a complete event nothing is pending.
     */
    public function test_nothing_pending_after_a_complete_event(): void {
        $parser = new sse_parser();

        $parser->feed("data: ok\n\n");

        $this->assertFalse($parser->has_pending());
    }

    /**
     * Multibyte characters split between chunks survive intact.
     */
    public function test_multibyte_payload_split_between_chunks(): void {
        $parser = new sse_parser();
        $line = "data: {\"text\":\"ñandú 日本\"}\n\n";
        $cut = strpos($line, "\xC3") + 1;

        $parser->feed(substr($line, 0, $cut));
        $events = $parser->feed(substr($line, $cut));

        $this->assertSame('{"text":"ñandú 日本"}', $events[0]['data']);
    }

    /**
     * Bytes arriving one at a time give the same result as one chunk.
     */
    public function test_byte_by_byte_feed_matches_single_chunk(): void {
        $stream = "event: message\r\ndata: {\"a\":1}\r\n\r\n: ping\r\n\r\nevent: done\r\ndata:\r\n\r\n";
        $whole = (new sse_parser())->feed($stream);

        $parser = new sse_parser();
        $bytewise = [];
        foreach (str_split($stream) as $byte) {
            $bytewise = array_merge($bytewise, $parser->feed($byte));
        }

        $this->assertSame($whole, $bytewise);
        $this->assertCount(2, $whole);
    }
}
