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
 * Watches one SSE pass of a template generation stream and resolves once it
 * pauses, completes, or fails. Decoding what an event means belongs to
 * generation_events.js; deciding what to do once the pass ends belongs to
 * generation_stream.js, which is why both are passed in rather than
 * imported, so none of the three import each other.
 *
 * @module     local_coursegen/local/courseai/template/generation_watch
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Close the pass's source once, then run the given action. Guards against
 * running twice: a pass can be finished by more than one listener racing
 * (a terminal message and a transient 'error', say).
 *
 * @param {Object} state {source: EventSource, settled: boolean}
 * @param {Function} action
 * @returns {void}
 */
const finishPass = (state, action) => {
    if (state.settled) {
        return;
    }
    state.settled = true;
    state.source.close();
    action();
};

/**
 * Fail the pass: close its source, report the message, and reject.
 *
 * @param {Object} state {source: EventSource, settled: boolean}
 * @param {Function} onFail Called with the message once the pass fails.
 * @param {Function} reject The pass promise's reject.
 * @param {string} message
 * @returns {void}
 */
const failPass = (state, onFail, reject, message) => finishPass(state, () => {
    onFail(message);
    reject(new Error(message));
});

/**
 * Handle one decoded 'message' event: apply it, and finish or fail the pass
 * once it reaches a terminal outcome.
 *
 * @param {Object} state {source: EventSource, settled: boolean}
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} applyEvent (data, progress) => outcome string.
 * @param {Function} onFail Called with a message once the pass fails.
 * @param {Function} resolve The pass promise's resolve.
 * @param {Function} reject The pass promise's reject.
 * @param {MessageEvent} event
 * @returns {void}
 */
const handleStreamMessage = (state, progress, applyEvent, onFail, resolve, reject, event) => {
    let data = null;
    try {
        data = JSON.parse(event.data);
    } catch (e) {
        return;
    }

    const outcome = applyEvent(data, progress);
    if (outcome === 'review' || outcome === 'completed') {
        // The stream is closed on both. A pause left open would be
        // reconnected by EventSource, which resumes the graph from the
        // same point and re-emits the same pause, forever.
        finishPass(state, () => resolve({outcome, data}));
    } else if (outcome === 'failed') {
        failPass(state, onFail, reject, data.message || 'The generation could not be completed.');
    }
};

/**
 * Watch one pass of the stream.
 *
 * A pass ends in one of three ways: the graph pauses for the review, the run
 * completes, or it fails. The first two are not the end of the work, only of
 * this connection, which is why the caller loops.
 *
 * @param {string} streamUrl
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} applyEvent (data, progress) => outcome string.
 * @param {Function} onFail Called with a message once the pass fails.
 * @returns {Promise<Object>} {outcome: 'review'|'completed', data}
 */
export const watchOnce = (streamUrl, progress, applyEvent, onFail) => new Promise((resolve, reject) => {
    const state = {source: new EventSource(streamUrl), settled: false};

    state.source.addEventListener('message', (event) => {
        handleStreamMessage(state, progress, applyEvent, onFail, resolve, reject, event);
    });

    state.source.addEventListener('done', () => {
        // A 'done' with no terminal event before it means the stream ended
        // without ever saying how: reported as a failure rather than leaving
        // the professor watching a header that will never resolve.
        failPass(state, onFail, reject, 'The generation ended unexpectedly.');
    });

    state.source.onerror = () => {
        // EventSource reconnects by itself on a transient drop, reporting
        // CONNECTING while it does; only a closed connection is a failure.
        if (state.source.readyState === EventSource.CONNECTING) {
            return;
        }
        failPass(state, onFail, reject, 'The connection to the generation was lost.');
    };
});
