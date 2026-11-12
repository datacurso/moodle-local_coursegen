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
 * The creation of the course of a finished generation, followed as a stream.
 *
 * A single request that creates the whole course stays silent for as long as it takes, and the proxies in front of a
 * site cut a silent request after two minutes. The server writes after each activity it makes, so this connection is
 * never silent; and when it is cut anyway, the stream is opened again, which waits for the creation that is running
 * instead of starting another.
 *
 * @module     local_coursegen/local/courseai/template/finish_stream
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import RelaySource from 'local_coursegen/local/courseai/stream/relay-source';
import {getString} from 'core/str';
import {ATTEMPTS, countdown, delayFor} from 'local_coursegen/local/courseai/template/retry_schedule';

/**
 * Where the stream is, with what the teacher chose at the review.
 *
 * @param {string} root Root of the site.
 * @param {number} sessionId
 * @param {Object} overrides {fullname, shortname, category}, each optional.
 * @returns {string}
 */
export const streamUrl = (root, sessionId, overrides) => {
    const url = new URL(root + '/local/coursegen/finish_template.php', window.location.href);
    url.searchParams.set('sessionid', sessionId);
    ['fullname', 'shortname', 'category'].forEach((key) => {
        if (overrides && overrides[key]) {
            url.searchParams.set(key, overrides[key]);
        }
    });
    return url.toString();
};

/**
 * Read the JSON of an event, or an empty object when it is not.
 *
 * @param {{data: string}} event
 * @returns {Object}
 */
const dataOf = (event) => {
    try {
        return JSON.parse(event.data) || {};
    } catch (e) {
        return {};
    }
};

/**
 * Open the stream once.
 *
 * @param {string} url
 * @param {Function} onProgress Called with {step, done, total, message}.
 * @returns {Promise<Object>} The created course; rejects with errorcode 'connection' when the connection is lost.
 */
const openOnce = (url, onProgress) => new Promise((resolve, reject) => {
    const source = new RelaySource(url);
    let settled = false;
    const settle = (action) => {
        if (settled) {
            return;
        }
        settled = true;
        source.close();
        action();
    };
    const failWith = (message, errorcode, retryable = false) => settle(() => reject(
        Object.assign(new Error(message), {errorcode, retryable})
    ));

    source.addEventListener('progress', (event) => onProgress(dataOf(event)));
    source.addEventListener('result', (event) => settle(() => resolve(dataOf(event))));
    source.addEventListener('failed', (event) => {
        const data = dataOf(event);
        failWith(data.message || '', data.errorcode || 'failed', data.retryable === true);
    });
    source.addEventListener('done', () => failWith('The creation ended without an answer.', 'connection'));
    source.onerror = (error) => failWith((error && error.message) || 'The connection was lost.', 'connection');
});

/**
 * The line that tells which retry it is and the time left, in the language of the person.
 *
 * @param {number} attempt
 * @param {number} seconds
 * @returns {Promise<string>}
 */
const describeWait = async(attempt, seconds) => {
    const text = await getString('courseai_template_retry_status', 'local_coursegen');
    return text.replace('{$a->attempt}', attempt).replace('{$a->total}', ATTEMPTS).replace('{$a->seconds}', seconds);
};

/**
 * Create the course and follow it.
 *
 * When the connection is lost the stream is opened again, waiting longer each time (ten seconds, twenty, forty, and
 * so on), and the page is told which retry it is and how long is left for the next.
 *
 * @param {string} root Root of the site.
 * @param {number} sessionId
 * @param {Object} overrides {fullname, shortname, category}, each optional.
 * @param {Function} [onProgress] Called with {step, done, total, message}.
 * @param {Object} [options] Replaced in tests.
 * @param {Function} [options.open] One opening of the stream.
 * @param {Function} [options.pause] One second of the countdown.
 * @returns {Promise<Object>} {success, courseid, fullname, shortname, message, courseurl}
 */
export const finishTemplateGenerationStream = async(
    root, sessionId, overrides, onProgress = () => undefined, {open = openOnce, pause = undefined} = {}
) => {
    const url = streamUrl(root, sessionId, overrides);
    for (let attempt = 0; ; attempt++) {
        try {
            // Each opening waits for the one before it, so the attempts are made one after the other.
            // eslint-disable-next-line no-await-in-loop
            return await open(url, onProgress);
        } catch (error) {
            // What waiting mends: the connection of the page, and what the server says may be retried (the service
            // could not be reached from Moodle, or answered with an error that passes).
            if (!(error.errorcode === 'connection' || error.retryable === true) || attempt >= ATTEMPTS) {
                throw error;
            }
        }
        const next = attempt + 1;
        const lines = new Map();
        // eslint-disable-next-line no-await-in-loop
        await countdown(delayFor(next), (left) => {
            lines.set(left, describeWait(next, left));
            lines.get(left).then((message) => onProgress({step: 'retrying', done: next, total: ATTEMPTS, message}));
        }, pause ? {pause} : {});
    }
};
