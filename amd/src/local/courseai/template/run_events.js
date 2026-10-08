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
 * Reading back the events of a run: its last failure and its last completion, and the words of a failure.
 *
 * @module     local_coursegen/local/courseai/template/run_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {failureText} from 'local_coursegen/local/courseai/template/failure_text';

/**
 * The last failed event of a run.
 *
 * @param {Array<Object>} events The events of the run, in order.
 * @returns {?Object} The event, or null when the run did not fail.
 */
export const lastFailure = (events) => {
    const failures = events.filter((event) => event && event.type === 'failed');
    return failures[failures.length - 1] || null;
};

/**
 * The last completed event of a run.
 *
 * @param {Array<Object>} events The events of the run, in order.
 * @returns {?Object} The event, or null when the run did not complete.
 */
export const lastCompleted = (events) => {
    const completed = events.filter((event) => event && event.type === 'completed');
    return completed[completed.length - 1] || null;
};

/**
 * Decode JSON text without ever throwing.
 *
 * @param {string} text JSON text, for example '[{"type":"failed"}]'.
 * @param {*} fallback What to answer when the text is not valid JSON.
 * @returns {*}
 */
export const parseJson = (text, fallback) => {
    try {
        return JSON.parse(text);
    } catch (exception) {
        return fallback;
    }
};

/**
 * The plain words of a failed event, or nothing when there is no failure.
 *
 * @param {?Object} failure The failed event, in any shape its message has.
 * @returns {Promise<string>} Empty when there is no failure.
 */
export const failureWords = async(failure) => {
    if (failure === null) {
        return '';
    }
    return failureText(failure);
};
