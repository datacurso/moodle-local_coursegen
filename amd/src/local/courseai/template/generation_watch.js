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
    const source = new EventSource(streamUrl);
    let settled = false;

    const finish = (action) => {
        if (settled) {
            return;
        }
        settled = true;
        source.close();
        action();
    };

    const fail = (message) => finish(() => {
        onFail(message);
        reject(new Error(message));
    });

    source.addEventListener('message', (event) => {
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
            finish(() => resolve({outcome, data}));
        } else if (outcome === 'failed') {
            fail(data.message || 'The generation could not be completed.');
        }
    });

    source.addEventListener('done', () => {
        // A 'done' with no terminal event before it means the stream ended
        // without ever saying how: reported as a failure rather than leaving
        // the professor watching a header that will never resolve.
        fail('The generation ended unexpectedly.');
    });

    source.onerror = () => {
        // EventSource reconnects by itself on a transient drop, reporting
        // CONNECTING while it does; only a closed connection is a failure.
        if (source.readyState === EventSource.CONNECTING) {
            return;
        }
        fail('The connection to the generation was lost.');
    };
});
