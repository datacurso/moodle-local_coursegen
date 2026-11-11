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
 * When to try again after a failure of the connection: the waits grow, so a failure that lasts has more time to pass.
 *
 * The first retry comes ten seconds after the failure, the second twenty seconds after that, the third forty, and
 * so on, doubling up to a ceiling. There are ten retries before the person is asked to decide.
 *
 * @module     local_coursegen/local/courseai/template/retry_schedule
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How many times a failure is retried by itself before the person decides. */
export const ATTEMPTS = 10;

/** Seconds to wait before the first retry. */
export const FIRST_DELAY_SECONDS = 10;

/** Seconds the wait never goes over, however many retries have been made. */
export const MAX_DELAY_SECONDS = 120;

/**
 * Seconds to wait before a retry.
 *
 * @param {number} attempt Number of the retry, from 1.
 * @returns {number}
 */
export const delayFor = (attempt) => Math.min(MAX_DELAY_SECONDS, FIRST_DELAY_SECONDS * (2 ** (attempt - 1)));

/**
 * Whether a failure is one that trying again can mend: the connection, or what the service says may be retried.
 *
 * @param {Object|null} error
 * @returns {boolean}
 */
export const isRetryable = (error) => !!error && (
    error.retryable === true || error.code === 'connection' || error.errorcode === 'connection'
);

/**
 * Pause one second.
 *
 * @returns {Promise<void>}
 */
const oneSecond = () => new Promise((resolve) => setTimeout(resolve, 1000));

let defaultPause = oneSecond;

/**
 * Replace the second that every countdown waits; tests use it so ten seconds do not take ten seconds.
 *
 * @param {Function|null} pause The replacement, or null for the real second.
 */
export const setPauseForTests = (pause) => {
    defaultPause = pause || oneSecond;
};

/**
 * Count down, telling each second how many are left.
 *
 * @param {number} seconds Seconds to wait.
 * @param {Function} onTick Called with the seconds left, from the full wait down to zero.
 * @param {Object} [options]
 * @param {Promise<void>} [options.skip] Ends the wait early when it resolves.
 * @param {Function} [options.pause] Pauses one second; replaced in tests.
 * @returns {Promise<string>} 'elapsed' when the wait ran out, 'skipped' when it was ended early.
 */
export const countdown = async(seconds, onTick, {skip = null, pause = null} = {}) => {
    const wait = pause || defaultPause;
    let skipped = false;
    if (skip) {
        skip.then(() => {
            skipped = true;
        });
    }
    for (let left = seconds; left >= 0; left--) {
        if (skipped) {
            return 'skipped';
        }
        onTick(left);
        if (left > 0) {
            // eslint-disable-next-line no-await-in-loop
            await Promise.race([wait(), skip || new Promise(() => undefined)]);
        }
    }
    return skipped ? 'skipped' : 'elapsed';
};
