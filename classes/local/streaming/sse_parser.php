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

namespace local_coursegen\local\streaming;

/**
 * Incremental parser of a server-sent events stream.
 *
 * Bytes are fed as they arrive from the network, in any chunking, and complete events are returned.
 * It follows the WHATWG event stream format: lines end in LF, CRLF or CR, a blank line ends an event,
 * lines that start with a colon are comments, several data lines are joined with a newline, and an event
 * cut off by the end of the stream is never returned.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sse_parser {
    /** @var string Event name used when the stream does not give one. */
    private const DEFAULT_EVENT = 'message';

    /** @var string UTF-8 byte order mark that may start a stream. */
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /** @var string Bytes received that do not form a complete line yet. */
    private string $buffer = '';

    /** @var bool Whether the start of the stream has been checked for a byte order mark. */
    private bool $started = false;

    /** @var string|null Event name of the event being built. */
    private ?string $eventname = null;

    /** @var string[] Data lines of the event being built. */
    private array $datalines = [];

    /** @var bool Whether the event being built has an event or data field. */
    private bool $hasfields = false;

    /**
     * Add received bytes and return the events they complete.
     *
     * @param string $chunk Raw bytes, which may end in the middle of a line or of a character.
     * @return array[] List of events, each with the keys 'event' and 'data'.
     */
    public function feed(string $chunk): array {
        $this->buffer .= $chunk;
        $this->skip_byte_order_mark();

        $events = [];
        $line = $this->next_line();
        while ($line !== null) {
            $event = $this->process_line($line);
            if ($event !== null) {
                $events[] = $event;
            }
            $line = $this->next_line();
        }

        return $events;
    }

    /**
     * Whether bytes of an event are waiting for the rest of it.
     *
     * After the stream ends this tells that the last event was cut off.
     *
     * @return bool
     */
    public function has_pending(): bool {
        return $this->buffer !== '' || $this->hasfields;
    }

    /**
     * Drop the byte order mark once, at the start of the stream.
     */
    private function skip_byte_order_mark(): void {
        if ($this->started) {
            return;
        }

        $couldbemark = strlen($this->buffer) < strlen(self::BYTE_ORDER_MARK)
            && str_starts_with(self::BYTE_ORDER_MARK, $this->buffer);
        if ($couldbemark) {
            return;
        }

        $this->started = true;
        if (str_starts_with($this->buffer, self::BYTE_ORDER_MARK)) {
            $this->buffer = substr($this->buffer, strlen(self::BYTE_ORDER_MARK));
        }
    }

    /**
     * Take the next complete line out of the buffer.
     *
     * A line that ends in CR is kept until the next byte shows whether an LF follows.
     *
     * @return string|null The line without its terminator, or null when no line is complete.
     */
    private function next_line(): ?string {
        $length = strcspn($this->buffer, "\r\n");
        $buffered = strlen($this->buffer);
        if ($length === $buffered) {
            return null;
        }

        $line = substr($this->buffer, 0, $length);
        $consume = $length + 1;
        if ($this->buffer[$length] === "\r") {
            if ($consume === $buffered) {
                return null;
            }
            if ($this->buffer[$consume] === "\n") {
                $consume++;
            }
        }

        $this->buffer = substr($this->buffer, $consume);
        return $line;
    }

    /**
     * Apply one line to the event being built.
     *
     * @param string $line Line without its terminator.
     * @return array|null The event the line completes, or null.
     */
    private function process_line(string $line): ?array {
        if ($line === '') {
            return $this->dispatch();
        }

        if ($line[0] === ':') {
            return null;
        }

        $this->apply_field($line);
        return null;
    }

    /**
     * Store the value of an event or data field. Any other field is ignored.
     *
     * @param string $line Field line.
     */
    private function apply_field(string $line): void {
        $separator = strpos($line, ':');
        $name = $line;
        $value = '';
        if ($separator !== false) {
            $name = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
        }

        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($name === 'event') {
            $this->eventname = $value;
            $this->hasfields = true;
        } else if ($name === 'data') {
            $this->datalines[] = $value;
            $this->hasfields = true;
        }
    }

    /**
     * Return the event being built and start a new one.
     *
     * @return array|null The event, or null when it has no event or data field.
     */
    private function dispatch(): ?array {
        if (!$this->hasfields) {
            return null;
        }

        $name = $this->eventname ?? self::DEFAULT_EVENT;
        if ($name === '') {
            $name = self::DEFAULT_EVENT;
        }
        $data = implode("\n", $this->datalines);

        $this->eventname = null;
        $this->datalines = [];
        $this->hasfields = false;

        return ['event' => $name, 'data' => $data];
    }
}
