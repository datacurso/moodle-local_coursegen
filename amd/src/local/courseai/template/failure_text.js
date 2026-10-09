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
 * The words a teacher reads when a template run fails, whatever shape the failure arrives in.
 *
 * The service and the browser report a failure as a sentence, as {code, message}, as a message it
 * localized ({string_id, string, string_args}), wrapped under detail or error, in a list, or as an
 * exception. This module reads every one of them and answers plain language: a known code gets its
 * own sentence, an unknown one gets the sentence that came with it, and when there is nothing safe
 * to show the generic sentence is used. Raw JSON, object markers, stack traces and codes are never shown.
 *
 * @module     local_coursegen/local/courseai/template/failure_text
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const COMPONENT = 'local_coursegen';
const GENERIC_KEY = 'template_agent_error_failed';
// How many levels of wrapping are followed, for example {error: {detail: {code: 'timeout'}}}.
const MAX_DEPTH = 4;
// Longer text than this is a dump, not a sentence for a teacher.
const MAX_TEXT_LENGTH = 300;
const MAX_ITEMS = 10;
const WRAPPER_FIELDS = ['message', 'detail', 'error', 'errors', 'data'];
const CODE_FIELDS = ['code', 'errorcode', 'error_code'];
const STATUS_FIELDS = ['status', 'statusCode'];
const TECHNICAL_PATTERNS = [
    /\[object /i,
    /traceback/i,
    /\b[A-Za-z]+(Error|Exception|Timeout)\s*:/,
    /^\s*at\s+\S+\s*\(/m,
];

const CODE_KEYS = new Map([
    ['fuse', 'template_error_too_big'],
    ['invalid_turn', 'template_error_not_understood'],
    ['syllabus_unavailable', 'template_error_no_syllabus'],
    ['nothing_to_start_from', 'template_error_nothing_to_start'],
    ['not_found', 'template_error_not_found'],
    ['document_too_long', 'template_error_document_too_long'],
    ['stream_error', 'template_agent_error_ended'],
    ['generation_failed', GENERIC_KEY],
    ['timeout', 'template_error_timeout'],
    ['read_timeout', 'template_error_timeout'],
    ['model_timeout', 'template_error_timeout'],
    ['request_timeout', 'template_error_timeout'],
    ['gateway_timeout', 'template_error_timeout'],
    ['rate_limited', 'template_error_rate_limit'],
    ['rate_limit', 'template_error_rate_limit'],
    ['too_many_requests', 'template_error_rate_limit'],
    ['upstream_error', 'template_error_upstream'],
    ['provider_error', 'template_error_upstream'],
    ['model_error', 'template_error_upstream'],
    ['bad_gateway', 'template_error_upstream'],
    ['service_unavailable', 'template_error_upstream'],
    ['invalidlicensekey', 'template_error_license'],
    ['license_required', 'template_error_license'],
    ['license_invalid', 'template_error_license'],
    ['license_missing', 'template_error_license'],
    ['license_expired', 'template_error_license'],
    ['file_save_failed', 'template_error_file_save'],
    ['file_not_saved', 'template_error_file_save'],
]);

const STATUS_KEYS = new Map([
    [401, 'template_error_license'],
    [402, 'template_error_license'],
    [403, 'template_error_license'],
    [408, 'template_error_timeout'],
    [429, 'template_error_rate_limit'],
    [500, 'template_error_upstream'],
    [502, 'template_error_upstream'],
    [503, 'template_error_upstream'],
    [504, 'template_error_timeout'],
]);

const knownKeys = new Set(CODE_KEYS.values());
for (const statusKey of STATUS_KEYS.values()) {
    knownKeys.add(statusKey);
}
knownKeys.add(GENERIC_KEY);

/** Every language string key a failure can be told with, so a test can check each one exists. */
export const FAILURE_KEYS = Array.from(knownKeys);

const isObject = (value) => {
    if (value === null) {
        return false;
    }
    if (typeof value !== 'object') {
        return false;
    }
    const listed = Array.isArray(value);
    return !listed;
};

const isTechnical = (text) => {
    for (const pattern of TECHNICAL_PATTERNS) {
        const found = pattern.test(text);
        if (found) {
            return true;
        }
    }
    return false;
};

/**
 * The sentence a value holds, or null when it is blank, too long or technical.
 *
 * @param {*} value Anything.
 * @returns {?string}
 */
const sentenceOf = (value) => {
    if (typeof value !== 'string') {
        return null;
    }
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }
    if (trimmed.length > MAX_TEXT_LENGTH) {
        return null;
    }
    if (isTechnical(trimmed)) {
        return null;
    }
    return trimmed;
};

const codeOf = (object) => {
    for (const field of CODE_FIELDS) {
        const value = object[field];
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed !== '') {
                return trimmed.toLowerCase();
            }
        }
    }
    return null;
};

const statusOf = (object) => {
    for (const field of STATUS_FIELDS) {
        const value = object[field];
        if (value === undefined || value === null) {
            continue;
        }
        const number = Number(value);
        if (STATUS_KEYS.has(number)) {
            return number;
        }
    }
    return null;
};

const decodeJson = (text) => {
    try {
        return JSON.parse(text);
    } catch (error) {
        return undefined;
    }
};

const readLocalized = (object, found) => {
    const stringId = object.string_id;
    if (typeof stringId !== 'string' || stringId.trim() === '') {
        return;
    }
    found.stringId = stringId.trim();
    const args = object.string_args;
    if (isObject(args)) {
        found.stringArgs = args;
    }
    if (found.text === null) {
        found.text = sentenceOf(object.string);
    }
};

const collectString = (value, depth, found) => {
    const trimmed = value.trim();
    const opensObject = trimmed.startsWith('{');
    const opensList = trimmed.startsWith('[');
    if (opensObject || opensList) {
        const decoded = decodeJson(trimmed);
        if (decoded !== undefined) {
            collect(decoded, depth + 1, found);
        }
        return;
    }
    if (found.text === null) {
        found.text = sentenceOf(trimmed);
    }
};

const collectItems = (items, depth, found) => {
    const firstItems = items.slice(0, MAX_ITEMS);
    for (const item of firstItems) {
        collect(item, depth + 1, found);
    }
};

const collectWrapped = (object, depth, found) => {
    for (const field of WRAPPER_FIELDS) {
        collect(object[field], depth + 1, found);
    }
};

const collectObject = (object, depth, found) => {
    const code = codeOf(object);
    if (found.code === null) {
        found.code = code;
    }
    const status = statusOf(object);
    if (found.status === null) {
        found.status = status;
    }
    if (found.stringId === null) {
        readLocalized(object, found);
    }
    collectWrapped(object, depth, found);
};

/**
 * Gather what a failure says: its code, its status, its localized id and its sentence.
 *
 * @param {*} value The failure, in any shape.
 * @param {number} depth How many wrappers were opened to get here.
 * @param {Object} found Mutable {code, status, stringId, stringArgs, text}.
 */
const collect = (value, depth, found) => {
    if (depth > MAX_DEPTH) {
        return;
    }
    const listed = Array.isArray(value);
    if (listed) {
        collectItems(value, depth, found);
        return;
    }
    if (typeof value === 'string') {
        collectString(value, depth, found);
        return;
    }
    if (isObject(value)) {
        collectObject(value, depth, found);
    }
};

const keyFor = (found) => {
    if (found.code !== null && CODE_KEYS.has(found.code)) {
        return CODE_KEYS.get(found.code);
    }
    if (found.status !== null && STATUS_KEYS.has(found.status)) {
        return STATUS_KEYS.get(found.status);
    }
    if (found.stringId !== null || found.text !== null) {
        return null;
    }
    return GENERIC_KEY;
};

/**
 * Read a failure of any shape into what can be said about it.
 *
 * @param {*} value A sentence, an object, a list, an exception, JSON text, anything or nothing.
 * @returns {{key: ?string, stringId: ?string, stringArgs: ?Object, text: ?string}} The language string key
 *     that tells it, or the localized message id, or the plain sentence; the generic key when there is nothing.
 */
export const describeFailure = (value) => {
    const found = {code: null, status: null, stringId: null, stringArgs: null, text: null};
    collect(value, 0, found);
    const key = keyFor(found);
    return {key, stringId: found.stringId, stringArgs: found.stringArgs, text: found.text};
};

const localizedText = async(view) => {
    try {
        const text = await getString(view.stringId, COMPONENT, view.stringArgs);
        // Moodle renders "[[key]]" for a string it does not have.
        if (typeof text === 'string' && !text.startsWith('[[')) {
            return text;
        }
    } catch (error) {
        return null;
    }
    return null;
};

/**
 * The plain sentence to show the teacher for a failure. It is always a non-empty string.
 *
 * @param {*} value The failure, in any shape.
 * @returns {Promise<string>}
 */
export const failureText = async(value) => {
    const view = describeFailure(value);
    if (view.key !== null) {
        return getString(view.key, COMPONENT);
    }
    if (view.stringId !== null) {
        const localized = await localizedText(view);
        if (localized !== null) {
            return localized;
        }
    }
    if (view.text !== null) {
        return view.text;
    }
    return getString(GENERIC_KEY, COMPONENT);
};
