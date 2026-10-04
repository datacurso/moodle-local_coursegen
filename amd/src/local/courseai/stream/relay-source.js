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
 * Reads the stream of a generation from the relay of this plugin.
 *
 * It offers the part of the EventSource interface the stream code uses (addEventListener, onmessage, onerror,
 * readyState and close), but it asks the relay with a POST that carries the Moodle session key, so Moodle
 * decides who may follow a generation, and it never reconnects by itself: a lost connection is a failure the
 * caller decides how to recover from. The 'done' event is the end of the stream.
 *
 * @module     local_coursegen/local/courseai/stream/relay-source
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import SseParser from './sse-parser';

const CONNECTING = 0;
const OPEN = 1;
const CLOSED = 2;

export default class RelaySource {
    /**
     * Open the stream.
     *
     * @param {string} url Relay URL given by the server.
     */
    constructor(url) {
        this.url = url;
        this.readyState = CONNECTING;
        this.onmessage = null;
        this.onerror = null;
        this.listeners = new Map();
        this.controller = new AbortController();
        this.parser = new SseParser();
        this.start();
    }

    /**
     * Listen to an event type, as EventSource does.
     *
     * @param {string} type Event name.
     * @param {Function} listener Called with an object that has type and data.
     */
    addEventListener(type, listener) {
        const current = this.listeners.get(type) || [];
        current.push(listener);
        this.listeners.set(type, current);
    }

    /**
     * Stop reading. The relay notices and stops the transfer from the service.
     */
    close() {
        this.readyState = CLOSED;
        this.controller.abort();
    }

    /**
     * Read the stream, and turn any failure into the error callback.
     */
    async start() {
        try {
            await this.read();
        } catch (error) {
            this.fail(error);
        }
    }

    /**
     * Ask the relay and read its answer until the stream ends.
     */
    async read() {
        const response = await fetch(this.requestUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'text/event-stream'},
            signal: this.controller.signal,
        });

        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !contentType.includes('text/event-stream')) {
            throw new Error('The relay did not open a stream (' + response.status + ')');
        }

        this.readyState = OPEN;
        await this.consume(response.body.getReader());

        if (this.readyState !== CLOSED) {
            throw new Error('The stream ended before its done event');
        }
    }

    /**
     * Relay URL with the session key the page holds.
     *
     * @returns {string}
     */
    requestUrl() {
        const url = new URL(this.url, window.location.href);
        url.searchParams.set('sesskey', Config.sesskey);
        return url.toString();
    }

    /**
     * Read chunks until the stream ends or is closed.
     *
     * @param {ReadableStreamDefaultReader} reader Reader of the response body.
     */
    async consume(reader) {
        const decoder = new TextDecoder();
        let chunk = await reader.read();
        while (!chunk.done && this.readyState !== CLOSED) {
            const text = decoder.decode(chunk.value, {stream: true});
            const events = this.parser.feed(text);
            this.dispatchAll(events);
            chunk = await reader.read();
        }
    }

    /**
     * Pass events on in order, stopping once the stream is closed.
     *
     * @param {Array<{event: string, data: string}>} events Events read.
     */
    dispatchAll(events) {
        for (const event of events) {
            if (this.readyState === CLOSED) {
                return;
            }
            this.dispatch(event);
        }
    }

    /**
     * Deliver one event. The done event ends the stream once its listeners ran.
     *
     * @param {{event: string, data: string}} event Event read.
     */
    dispatch(event) {
        const payload = {type: event.event, data: event.data};
        const listeners = this.listeners.get(event.event) || [];
        for (const listener of listeners) {
            this.invoke(listener, payload);
        }

        if (event.event === 'message' && this.onmessage) {
            this.invoke(this.onmessage, payload);
        }

        if (event.event === 'done') {
            this.close();
        }
    }

    /**
     * Run a listener without letting its failure stop the stream.
     *
     * @param {Function} listener Listener to run.
     * @param {Object} payload Event object.
     */
    invoke(listener, payload) {
        try {
            listener(payload);
        } catch (error) {
            // Report it like a browser does for an EventSource listener, and keep reading.
            window.dispatchEvent(new ErrorEvent('error', {error, message: String(error && error.message)}));
        }
    }

    /**
     * The stream failed: close it and tell the caller, unless the caller closed it first.
     *
     * @param {Error} error What went wrong.
     */
    fail(error) {
        if (this.readyState === CLOSED) {
            return;
        }

        this.readyState = CLOSED;
        if (this.onerror) {
            this.invoke(this.onerror, error);
        }
    }
}

RelaySource.CONNECTING = CONNECTING;
RelaySource.OPEN = OPEN;
RelaySource.CLOSED = CLOSED;
