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
const TOOLS = new Set([
    'list_template',
    'get_activity',
    'get_draft',
    'create_section',
    'modify_activity',
    'attach_file',
    'set_link',
    'ask_user',
    'finish',
]);

/**
 * The row of an activity of the template: its course module id, for example "11342" for "t:11342".
 *
 * @param {*} aid Draft id of an activity.
 * @returns {string} The uid of the row, or an empty text when the activity has no row.
 */
export const rowUid = (aid) => {
    if (typeof aid !== 'string') {
        return '';
    }
    const match = TEMPLATE_AID.exec(aid);
    if (match === null) {
        return '';
    }
    return match[1];
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
 * @returns {Object} The event, or an empty object when it is not one.
 */
export const normalizeEvent = (event) => {
    if (event === null || typeof event !== 'object' || Array.isArray(event)) {
        return {};
    }
    const copy = {...event};
    copy.uid = safeId(copy.uid);
    if (copy.uid === '') {
        copy.uid = rowUid(copy.aid);
    }
    if (copy.uid === '') {
        copy.uid = safeId(copy.aid);
    }
    if (!copy.name && copy.title) {
        copy.name = copy.title;
    }
    return copy;
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
