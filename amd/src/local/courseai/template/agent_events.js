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

/**
 * Reads the events of the template agent: which row of the structure an activity id belongs to, what label a
 * tool has, what kind of answer a question needs and which events were already shown. Pure functions with no
 * DOM, so the page and the node tests use the same code.
 *
 * @module     local_coursegen/local/courseai/template/agent_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const TEMPLATE_AID = /^t:([0-9]+)$/;
const SAFE_ID = /^[A-Za-z0-9:_.-]+$/;

const TOOL_LABEL_PREFIX = 'template_agent_tool_';
const NAMED_SUFFIX = '_named';
const QUESTION_TITLE = 'template_agent_question_title';
const QUESTION_TITLE_FILE = 'template_agent_question_title_file';

/** The most characters of an activity name the page shows; a longer name is cut with an ellipsis. */
export const MAX_NAME_CHARS = 60;

/** The tools whose line in the feed names the activity they are about. */
const NAMED_TOOLS = new Set([
    'get_activity',
    'modify_activity',
    'attach_file',
    'create_file_for_activity',
    'set_link',
    'ask_user',
]);
const TOOLS = new Set([
    'list_template',
    'get_activity',
    'get_draft',
    'create_section',
    'modify_activity',
    'attach_file',
    'create_file_for_activity',
    'set_link',
    'ask_user',
    'finish',
]);

/**
 * The uid of the row that shows an activity, read off the page: the row names the course module the service
 * calls the activity by.
 *
 * @param {string} cmid Course module id, for example "11342".
 * @returns {string} The opaque uid of the row, or an empty text when no row shows that activity.
 */
const uidOfRowWithCmid = (cmid) => {
    if (typeof document === 'undefined') {
        return '';
    }
    const row = document.querySelector(`[data-generation-cmid="${cmid}"]`);
    if (row === null) {
        return '';
    }
    return safeId(row.dataset.generationUid);
};

/**
 * The row of an activity of the template, which the service names "t:" and the number of its course module.
 *
 * @param {*} aid Draft id of an activity, for example "t:11342".
 * @param {Function} rowLookup Gives the uid of the row of a course module id; by default it reads the page.
 * @returns {string} The opaque uid of the row, or an empty text when the activity has no row.
 */
export const rowUid = (aid, rowLookup = uidOfRowWithCmid) => {
    if (typeof aid !== 'string') {
        return '';
    }
    const match = TEMPLATE_AID.exec(aid);
    if (match === null) {
        return '';
    }
    return rowLookup(match[1]);
};

/**
 * The id the page puts in a selector: letters, digits and the separators of the draft ids, or an empty text.
 *
 * @param {*} value Id of an activity.
 * @returns {string} The id, or an empty text when it could break a selector.
 */
export const safeId = (value) => {
    if (typeof value === 'string' && SAFE_ID.test(value)) {
        return value;
    }
    return '';
};

/**
 * A copy of an event with the uid of its row and its name, the way the progress handlers read them.
 *
 * @param {*} event Event of the stream.
 * @param {Function} rowLookup Gives the uid of the row of a course module id; by default it reads the page.
 * @returns {Object} The event, or an empty object when it is not one.
 */
export const normalizeEvent = (event, rowLookup = uidOfRowWithCmid) => {
    if (event === null || typeof event !== 'object' || Array.isArray(event)) {
        return {};
    }
    const copy = {...event};
    copy.uid = safeId(copy.uid);
    if (copy.uid === '') {
        copy.uid = rowUid(copy.aid, rowLookup);
    }
    if (copy.uid === '') {
        copy.uid = safeId(copy.aid);
    }
    if (!copy.name && copy.title) {
        copy.name = copy.title;
    }
    return copy;
};

/** The id the service gives to the status that counts the seconds of a call in flight. */
const WAITING_STRING_ID = 'agent_waiting';

/**
 * The whole seconds a waiting status says the AI has been working on one call.
 *
 * @param {*} event A decoded event, for example a status whose message has string_id "agent_waiting" and seconds 35.
 * @returns {number} The seconds, or -1 when the event is not a waiting status or its seconds are not a number.
 */
export const waitingSeconds = (event) => {
    if (event === null || typeof event !== 'object' || event.type !== 'status') {
        return -1;
    }
    const message = event.message;
    if (message === null || typeof message !== 'object' || message.string_id !== WAITING_STRING_ID) {
        return -1;
    }
    const args = message.string_args;
    if (args === null || typeof args !== 'object') {
        return -1;
    }
    const seconds = Number(args.seconds);
    if (!Number.isFinite(seconds) || seconds < 0) {
        return -1;
    }
    return Math.floor(seconds);
};

/**
 * The language string key that names a tool.
 *
 * @param {*} name Name of the tool, for example "modify_activity".
 * @returns {string} The key of its label, or the generic one for a tool the page does not know.
 */
export const toolLabelKey = (name) => {
    if (typeof name === 'string' && TOOLS.has(name)) {
        return TOOL_LABEL_PREFIX + name;
    }
    return TOOL_LABEL_PREFIX + 'generic';
};

/**
 * The name of an activity as the page shows it: trimmed, and cut with an ellipsis when it is very long.
 *
 * @param {*} value Display name of an activity, for example "Weekly guide".
 * @returns {string} The name, or an empty text when the value is not a usable text.
 */
const cleanName = (value) => {
    if (typeof value !== 'string') {
        return '';
    }
    const name = value.trim();
    if (name.length <= MAX_NAME_CHARS) {
        return name;
    }
    return name.slice(0, MAX_NAME_CHARS - 1).trimEnd() + '…';
};

/**
 * The line of the feed for a call: the label of the tool, and the name of the activity when the line names one.
 *
 * @param {*} data The tool_call event, for example {name: "get_activity", activity_name: "Weekly guide"}.
 * @returns {{key: string, argument: (string|undefined)}} The language string key and, for a named line, its argument.
 */
export const stepLabel = (data) => {
    if (data === null || typeof data !== 'object') {
        return {key: toolLabelKey(undefined)};
    }
    const key = toolLabelKey(data.name);
    const activity = cleanName(data.activity_name);
    if (activity === '' || !NAMED_TOOLS.has(data.name)) {
        return {key};
    }
    return {key: key + NAMED_SUFFIX, argument: activity};
};

/**
 * The language string key of the title of a question card: a file that is missing, or more information.
 *
 * @param {*} question The question event.
 * @returns {string} The key of the title.
 */
export const questionTitleKey = (question) => {
    if (question !== null && typeof question === 'object' && question.ask_for_file === true) {
        return QUESTION_TITLE_FILE;
    }
    return QUESTION_TITLE;
};

/**
 * The name of the activity a question is about.
 *
 * @param {*} question The question event.
 * @returns {string} The name, or an empty text when the question is about no activity.
 */
export const questionActivity = (question) => {
    if (question === null || typeof question !== 'object') {
        return '';
    }
    return cleanName(question.activity_name);
};

/**
 * What a question needs as an answer.
 *
 * @param {*} question The question event.
 * @returns {string} "file", "choice" or "text".
 */
export const questionKind = (question) => {
    if (question === null || typeof question !== 'object') {
        return 'text';
    }
    if (question.ask_for_file === true) {
        return 'file';
    }
    if (questionOptions(question).length > 0) {
        return 'choice';
    }
    return 'text';
};

/**
 * How a failed event ends the pass: a failure the teacher can try again is a retry, any other is final.
 *
 * @param {*} failure The failed event, for example {type: 'failed', retryable: true, message: 'Try again'}.
 * @returns {string} "retry" or "failed".
 */
export const failureOutcome = (failure) => {
    if (failure === null || typeof failure !== 'object') {
        return 'failed';
    }
    if (failure.retryable === true) {
        return 'retry';
    }
    return 'failed';
};

const cleanOption = (option) => {
    if (typeof option !== 'string') {
        return '';
    }
    return option.trim();
};

/**
 * The options of a question: non-empty texts, trimmed, each once.
 *
 * @param {*} question The question event.
 * @returns {string[]} The options.
 */
export function questionOptions(question) {
    if (question === null || typeof question !== 'object' || !Array.isArray(question.options)) {
        return [];
    }
    const options = [];
    for (const option of question.options) {
        const text = cleanOption(option);
        if (text !== '' && !options.includes(text)) {
            options.push(text);
        }
    }
    return options;
}

const IDENTIFIED = new Set(['tool_call', 'tool_result', 'question']);
const PROGRESS = new Set(['activity_progress_start', 'activity_progress_done', 'activity_progress_failed']);

const identityOf = (event) => {
    if (IDENTIFIED.has(event.type) && event.call_id) {
        return event.type + ':' + event.call_id;
    }
    if (PROGRESS.has(event.type) && (event.aid || event.uid)) {
        return event.type + ':' + (event.aid || event.uid);
    }
    return '';
};

/**
 * What the page already showed, so a replay followed by the live stream never shows an event twice.
 */
class SeenEvents {
    /**
     * Start with nothing seen.
     */
    constructor() {
        this.known = new Set();
    }

    /**
     * Tell whether an event is new, and remember it.
     *
     * @param {*} event Event of the stream.
     * @returns {boolean} True when the page has not shown this event before.
     */
    accept(event) {
        if (event === null || typeof event !== 'object' || Array.isArray(event)) {
            return false;
        }
        const identity = identityOf(event);
        if (identity === '') {
            return true;
        }
        if (this.known.has(identity)) {
            return false;
        }
        this.known.add(identity);
        return true;
    }

    /**
     * Forget everything seen.
     */
    reset() {
        this.known = new Set();
    }
}

/**
 * A new record of what was shown.
 *
 * @returns {SeenEvents} The record.
 */
export const createSeen = () => new SeenEvents();
