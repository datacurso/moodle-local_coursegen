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

/**
 * Incremental parser of a server-sent events stream.
 *
 * Text is fed as it is decoded from the network, in any chunking, and complete events are returned. It follows
 * the same rules as the PHP parser of the relay: lines end in LF, CRLF or CR, a blank line ends an event, lines
 * that start with a colon are comments, several data lines are joined with a newline, and an event cut off by
 * the end of the stream is never returned.
 *
 * @module     local_coursegen/local/courseai/stream/sse-parser
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Event name used when the stream does not give one. */
const DEFAULT_EVENT = 'message';

/** Line terminators, longest first so CRLF is one terminator. */
const LINE_END = /\r\n|\r|\n/;

/** Byte order mark that may start a stream. */
const BYTE_ORDER_MARK = '﻿';

export default class SseParser {
    constructor() {
        this.buffer = '';
        this.started = false;
        this.eventName = null;
        this.dataLines = [];
        this.hasFields = false;
    }

    /**
     * Add received text and return the events it completes.
     *
     * @param {string} chunk Decoded text, which may end in the middle of a line.
     * @returns {Array<{event: string, data: string}>}
     */
    feed(chunk) {
        this.buffer += chunk;
        this.skipByteOrderMark();

        const events = [];
        let line = this.nextLine();
        while (line !== null) {
            const event = this.processLine(line);
            if (event !== null) {
                events.push(event);
            }
            line = this.nextLine();
        }

        return events;
    }

    /**
     * Whether text of an event is waiting for the rest of it.
     *
     * @returns {boolean}
     */
    hasPending() {
        return this.buffer !== '' || this.hasFields;
    }

    /**
     * Drop the byte order mark once, at the start of the stream.
     */
    skipByteOrderMark() {
        if (this.started || this.buffer === '') {
            return;
        }

        this.started = true;
        if (this.buffer.startsWith(BYTE_ORDER_MARK)) {
            this.buffer = this.buffer.slice(BYTE_ORDER_MARK.length);
        }
    }

    /**
     * Take the next complete line out of the buffer.
     *
     * A line that ends in CR is kept until the next text shows whether an LF follows.
     *
     * @returns {string|null} The line without its terminator, or null when no line is complete.
     */
    nextLine() {
        const match = LINE_END.exec(this.buffer);
        if (match === null) {
            return null;
        }

        const end = match.index + match[0].length;
        if (match[0] === '\r' && end === this.buffer.length) {
            return null;
        }

        const line = this.buffer.slice(0, match.index);
        this.buffer = this.buffer.slice(end);
        return line;
    }

    /**
     * Apply one line to the event being built.
     *
     * @param {string} line Line without its terminator.
     * @returns {{event: string, data: string}|null} The event the line completes, or null.
     */
    processLine(line) {
        if (line === '') {
            return this.dispatch();
        }

        if (line.startsWith(':')) {
            return null;
        }

        this.applyField(line);
        return null;
    }

    /**
     * Store the value of an event or data field. Any other field is ignored.
     *
     * @param {string} line Field line.
     */
    applyField(line) {
        const separator = line.indexOf(':');
        let name = line;
        let value = '';
        if (separator !== -1) {
            name = line.slice(0, separator);
            value = line.slice(separator + 1);
        }

        if (value.startsWith(' ')) {
            value = value.slice(1);
        }

        if (name === 'event') {
            this.eventName = value;
            this.hasFields = true;
        } else if (name === 'data') {
            this.dataLines.push(value);
            this.hasFields = true;
        }
    }

    /**
     * Return the event being built and start a new one.
     *
     * @returns {{event: string, data: string}|null} The event, or null when it has no event or data field.
     */
    dispatch() {
        if (!this.hasFields) {
            return null;
        }

        let name = this.eventName;
        if (name === null || name === '') {
            name = DEFAULT_EVENT;
        }
        const data = this.dataLines.join('\n');

        this.eventName = null;
        this.dataLines = [];
        this.hasFields = false;

        return {event: name, data};
    }
}
