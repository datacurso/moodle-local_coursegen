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
 * What the page decides between the end of a template run and the creation of the course.
 *
 * The run no longer builds the course when it completes: the teacher first reads what was generated and
 * either accepts it or asks for a change, and a change makes the run continue from its draft and complete
 * again. Pure decisions live here, with no DOM and no network, so each of them can be tested alone;
 * generation_review.js draws the review and generation_stream.js moves between the screens.
 *
 * @module     local_coursegen/local/courseai/template/review_flow
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The longest change request the page sends, in characters. */
export const MAX_INSTRUCTION = 4000;

const DIGITS = /^[0-9]+$/;
const ID_LENGTH_FROM_RANDOM = 12;

/**
 * The activities a completed event reports as generated.
 *
 * @param {*} data The completed event.
 * @returns {Array<Object>} Rows of generated_activities, or an empty list.
 */
export const generatedActivities = (data) => {
    if (data === null || typeof data !== 'object') {
        return [];
    }
    const result = data.result;
    if (result === null || typeof result !== 'object') {
        return [];
    }
    const list = result.generated_activities;
    if (!Array.isArray(list)) {
        return [];
    }
    return list;
};

/**
 * Whether the AI was asked to change an activity: the only ones a change request can name.
 *
 * @param {*} entry One row of generated_activities.
 * @returns {boolean}
 */
const isOpenToChanges = (entry) => {
    if (entry === null || typeof entry !== 'object') {
        return false;
    }
    const behavior = entry.template_behavior;
    if (behavior === null || typeof behavior !== 'object') {
        return false;
    }
    return behavior.action === 'modify';
};

/**
 * The generated activities that can be adjusted from their row.
 *
 * @param {*} generated Rows of generated_activities.
 * @returns {Array<Object>}
 */
export const adjustable = (generated) => {
    if (!Array.isArray(generated)) {
        return [];
    }
    return generated.filter(isOpenToChanges);
};

/**
 * The draft id the service gives an activity of the template, from the uid of its row.
 *
 * @param {*} uid Course module id of the template activity, for example "11342".
 * @returns {string} "t:11342", or an empty text when the row is not an activity of the template.
 */
export const rowAid = (uid) => {
    if (uid === null || uid === undefined) {
        return '';
    }
    const text = String(uid);
    const valid = DIGITS.test(text);
    if (!valid) {
        return '';
    }
    return 't:' + text;
};

/**
 * A new id for one change request, so a request sent twice is recognised by the service.
 *
 * @param {Function} random Source of numbers between 0 and 1, replaceable in tests.
 * @returns {string} Letters and digits only.
 */
export const newCallId = (random = Math.random) => {
    const first = random();
    const scaled = Math.floor(first * Number.MAX_SAFE_INTEGER);
    const body = scaled.toString(36).padStart(ID_LENGTH_FROM_RANDOM, '0');
    const stamp = Date.now().toString(36);
    return 'adj' + body + stamp;
};

/**
 * The reason to show for a request that failed.
 *
 * @param {*} error What the request threw.
 * @param {string} fallback Text to show when the error has none.
 * @returns {string}
 */
export const errorMessage = (error, fallback) => {
    if (error === null || typeof error !== 'object') {
        return fallback;
    }
    const message = error.message;
    if (typeof message !== 'string') {
        return fallback;
    }
    const trimmed = message.trim();
    if (trimmed === '') {
        return fallback;
    }
    return trimmed;
};

/**
 * The last event of a type, in the order the service sent them.
 *
 * @param {Array<Object>} events
 * @param {string} type
 * @returns {Object|null}
 */
const lastOfType = (events, type) => {
    for (let index = events.length - 1; index >= 0; index--) {
        const event = events[index];
        if (event && event.type === type) {
            return event;
        }
    }
    return null;
};

/**
 * The text of a change request, trimmed.
 *
 * @param {*} value
 * @returns {string}
 */
const instructionOf = (value) => {
    if (typeof value !== 'string') {
        return '';
    }
    return value.trim();
};

/**
 * The activities a change request is aimed at, as row uids.
 *
 * @param {*} value
 * @returns {Array<string>}
 */
const targetsOf = (value) => {
    if (!Array.isArray(value)) {
        return [];
    }
    return value.map((entry) => String(entry));
};

/**
 * The draft id of the only activity a request names, or an empty text when it names none or several.
 *
 * @param {Array<string>} targetIds
 * @returns {string}
 */
const aimedAid = (targetIds) => {
    if (targetIds.length !== 1) {
        return '';
    }
    return rowAid(targetIds[0]);
};

/**
 * The screen of a page that reloads with a failed run.
 *
 * @param {Array<Object>} events
 * @returns {{screen: string}}
 */
const failureScreen = (events) => {
    const failure = lastOfType(events, 'failed');
    if (failure !== null && failure.retryable === true) {
        return {screen: 'retry'};
    }
    return {screen: 'failed'};
};

/**
 * The state of the review of one run: the activities being reviewed, whether a change is on its way and
 * whether the run is working on one.
 */
export class ReviewFlow {
    /**
     * Start with nothing reviewed.
     */
    constructor() {
        this.generated = [];
        this.pending = false;
        this.adjusting = false;
    }

    /**
     * The run completed: show the review of what it generated.
     *
     * @param {Object} data The completed event.
     * @returns {{screen: string, generated: Array<Object>}}
     */
    completed(data) {
        this.generated = generatedActivities(data);
        this.pending = false;
        this.adjusting = false;
        return {screen: 'review', generated: this.generated};
    }

    /**
     * The teacher decided: accept the course or ask for a change.
     *
     * @param {{action: string, targetIds: Array<string>, instruction: string}} decision
     * @returns {Object} {screen: 'accepted'}, {send}, {error} or {ignored: true}.
     */
    submit(decision) {
        if (this.pending) {
            return {ignored: true};
        }
        const action = decision && decision.action;
        if (action === 'accept') {
            return {screen: 'accepted'};
        }
        if (action !== 'adjust') {
            return {ignored: true};
        }
        return this.requestChange(decision);
    }

    /**
     * Check a change request and, when it is valid, give what has to be sent.
     *
     * @param {Object} decision
     * @returns {{send: Object}|{error: string}}
     */
    requestChange(decision) {
        const instruction = instructionOf(decision.instruction);
        if (instruction === '') {
            return {error: 'blank'};
        }
        if (instruction.length > MAX_INSTRUCTION) {
            return {error: 'toolong'};
        }
        const targetIds = targetsOf(decision.targetIds);
        const aid = aimedAid(targetIds);
        const callId = newCallId();
        this.pending = true;
        return {send: {instruction, targetIds, aid, callId}};
    }

    /**
     * The service refused the change request: keep the review and show why.
     *
     * @param {*} error What the request threw.
     * @returns {{screen: string, error: string}}
     */
    feedbackFailed(error) {
        this.pending = false;
        return {screen: 'review', error: errorMessage(error, '')};
    }

    /**
     * The service stored the change request: the run goes on from its draft.
     *
     * @returns {{screen: string}}
     */
    feedbackStored() {
        this.pending = false;
        this.adjusting = true;
        return {screen: 'adjusting'};
    }

    /**
     * The run paused on a question.
     *
     * @returns {{screen: string}}
     */
    question() {
        return {screen: 'question'};
    }

    /**
     * The teacher answered the question the run was paused on.
     *
     * @returns {{screen: string}}
     */
    answered() {
        if (this.adjusting) {
            return {screen: 'adjusting'};
        }
        return {screen: 'running'};
    }

    /**
     * The screen of a page that reloads with a run in the given state.
     *
     * @param {Object|null} snapshot The state of the run, with its status.
     * @param {Array<Object>|null} events The events the run emitted so far, in order.
     * @returns {Object} {screen} and, for the review, the generated activities.
     */
    restore(snapshot, events) {
        let status = '';
        if (snapshot && typeof snapshot.status === 'string') {
            status = snapshot.status;
        }
        let list = [];
        if (Array.isArray(events)) {
            list = events;
        }
        return this.screenFor(status, list);
    }

    /**
     * Choose the screen for a status once the events are known to be a list.
     *
     * @param {string} status
     * @param {Array<Object>} events
     * @returns {Object}
     */
    screenFor(status, events) {
        if (status === 'WAITING_USER') {
            return this.question();
        }
        if (status === 'FAILED') {
            return failureScreen(events);
        }
        const completed = lastOfType(events, 'completed');
        if (status === 'COMPLETED') {
            return this.completed(completed);
        }
        if (status === 'RUNNING' && completed !== null) {
            this.generated = generatedActivities(completed);
            this.adjusting = true;
            return {screen: 'adjusting'};
        }
        return {screen: 'running'};
    }
}
