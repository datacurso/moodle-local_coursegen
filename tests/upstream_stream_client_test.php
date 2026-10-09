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

use local_coursegen\local\streaming\upstream_stream_client;

/**
 * Unit tests for the reader of the service stream.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\upstream_stream_client
 */
final class upstream_stream_client_test extends \basic_testcase {
    /** @var array Events received. */
    private array $events = [];

    /** @var bool Value the tick callback answers. */
    private bool $cancel = false;

    /** @var int Number of tick calls. */
    private int $ticks = 0;

    /**
     * Callback that records an event.
     *
     * @param array $event Event received.
     */
    public function on_event(array $event): void {
        $this->events[] = $event;
    }

    /**
     * Callback that counts ticks and answers the configured value.
     *
     * @return bool
     */
    public function on_tick(): bool {
        $this->ticks++;
        return $this->cancel;
    }

    /**
     * Build a client wired to this test.
     *
     * @return upstream_stream_client
     */
    private function client(): upstream_stream_client {
        $client = new upstream_stream_client();
        $client->listen([$this, 'on_event'], [$this, 'on_tick']);
        return $client;
    }

    /**
     * Bytes are parsed and every event reaches the callback in order.
     */
    public function test_receive_passes_events_on(): void {
        $client = $this->client();

        $bytes = "event: message\r\ndata: {\"a\":1}\r\n\r\nevent: done\r\ndata:\r\n\r\n";
        $taken = $client->receive(null, $bytes);

        $this->assertSame(strlen($bytes), $taken);
        $this->assertSame([
            ['event' => 'message', 'data' => '{"a":1}'],
            ['event' => 'done', 'data' => ''],
        ], $this->events);
    }

    /**
     * All bytes are taken, which keeps curl reading.
     */
    public function test_receive_takes_every_byte(): void {
        $client = $this->client();

        $bytes = ": ping\n\n";

        $this->assertSame(strlen($bytes), $client->receive(null, $bytes));
    }

    /**
     * An event split over two network reads is passed on once.
     */
    public function test_receive_joins_events_split_across_reads(): void {
        $client = $this->client();

        $client->receive(null, "event: message\ndata: {\"a\"");
        $client->receive(null, ":1}\n\n");

        $this->assertSame([['event' => 'message', 'data' => '{"a":1}']], $this->events);
    }

    /**
     * When the tick callback asks to stop, the read reports no bytes taken, which makes curl abort.
     */
    public function test_receive_stops_the_transfer_when_asked(): void {
        $client = $this->client();
        $this->cancel = true;

        $taken = $client->receive(null, "data: x\n\n");

        $this->assertSame(0, $taken);
        $this->assertCount(1, $this->events);
    }

    /**
     * Progress keeps the transfer going while the tick callback allows it.
     */
    public function test_progress_continues_by_default(): void {
        $client = $this->client();

        $this->assertSame(0, $client->progress(null, 0, 0, 0, 0));
        $this->assertSame(1, $this->ticks);
    }

    /**
     * Progress stops the transfer when the tick callback asks, even if no bytes arrive.
     */
    public function test_progress_stops_the_transfer_when_asked(): void {
        $client = $this->client();
        $this->cancel = true;

        $this->assertSame(1, $client->progress(null, 0, 0, 0, 0));
    }

    /**
     * Once cancelled the transfer stays cancelled and the tick callback is not asked again.
     */
    public function test_cancellation_is_final(): void {
        $client = $this->client();
        $this->cancel = true;
        $client->progress(null, 0, 0, 0, 0);
        $this->cancel = false;

        $this->assertSame(1, $client->progress(null, 0, 0, 0, 0));
        $this->assertSame(0, $client->receive(null, ": ping\n\n"));
        $this->assertSame(1, $this->ticks);
    }

    /**
     * Listening again starts a clean transfer.
     */
    public function test_listen_resets_the_cancellation(): void {
        $client = $this->client();
        $this->cancel = true;
        $client->progress(null, 0, 0, 0, 0);
        $this->cancel = false;

        $client->listen([$this, 'on_event'], [$this, 'on_tick']);

        $this->assertSame(0, $client->progress(null, 0, 0, 0, 0));
    }
}
