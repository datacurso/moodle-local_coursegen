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
 * Text and activity type normalization utilities for the SSE stream.
 *
 * @module     local_coursegen/local/courseai/stream/normalize
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Strip diacritics, lowercase, and trim a string.
 *
 * @param {*} value
 * @returns {string}
 */
export const normalizeText = (value) => (value || '')
    .toString()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();

/**
 * Map a free-text activity type string to a canonical Moodle module name.
 *
 * @param {string} rawType
 * @returns {string}
 */
export const normalizeActivityType = (rawType) => {
    const cleaned = normalizeText(rawType).replace(/\s+/g, ' ');
    const aliases = {
        forum: 'forum',
        page: 'page',
        book: 'book',
        quiz: 'quiz',
        'quiz blueprint': 'quiz',
        assignment: 'assign',
        task: 'assign',
        resource: 'resource',
        file: 'resource',
        folder: 'folder',
        label: 'label',
        'text and media area': 'label',
        database: 'data',
        glossary: 'glossary',
        lesson: 'lesson',
        url: 'url',
        wiki: 'wiki',
        workshop: 'workshop',
        scorm: 'scorm',
        'scorm package': 'scorm',
        imscp: 'imscp',
        'ims content package': 'imscp',
        feedback: 'feedback',
        choice: 'choice',
        survey: 'survey',
        h5p: 'h5pactivity',
        'h5p activity': 'h5pactivity',
        certificate: 'customcert',
        customcert: 'customcert',
        chat: 'chat',
        lti: 'lti',
    };

    if (aliases[cleaned]) {
        return aliases[cleaned];
    }

    const firstWord = cleaned.split(' ')[0] || cleaned;
    if (aliases[firstWord]) {
        return aliases[firstWord];
    }

    return cleaned.replace(/\s+/g, '_');
};

/**
 * Parse an activity start status text and return type + title, or null.
 *
 * @param {string} statusText
 * @returns {{type: string, title: string}|null}
 */
export const extractActivityFromStatus = (statusText) => {
    const text = statusText || '';

    let match = text.match(/^Designing\s+([^:]+):\s+(.+?)\.\.\./i);
    if (match) {
        return {
            type: normalizeActivityType(match[1]),
            title: match[2].trim(),
        };
    }

    match = text.match(/^Designing\s+Quiz\s+Blueprint\s+for:\s+(.+?)\.\.\./i);
    if (match) {
        return {
            type: 'quiz',
            title: match[1].trim(),
        };
    }

    match = text.match(/^Generating\s+Assignment\s+content\s+for:\s+(.+?)\.\.\./i);
    if (match) {
        return {
            type: 'assign',
            title: match[1].trim(),
        };
    }

    return null;
};

/**
 * Return true when the status text signals that an activity just finished.
 *
 * @param {string} statusText
 * @returns {boolean}
 */
export const isActivityDoneStatus = (statusText) => {
    const text = statusText || '';
    return /^(?:[A-Za-z][A-Za-z\s0-9/_-]*\s+ready:|Assembling final Quiz package\.\.\.)/i.test(text);
};

/**
 * Convert a snake_case or underscore type string to Title Case for display.
 *
 * @param {string} type
 * @returns {string}
 */
export const humanizeType = (type) => String(type || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

/**
 * Return the localized label for an activity type, falling back to humanizeType.
 *
 * @param {string} type
 * @param {Object} texts
 * @returns {string}
 */
export const getActivityLabel = (type, texts) => {
    const keyMap = {
        quiz: 'courseai_activity_quiz',
        book: 'courseai_activity_book',
        assign: 'courseai_activity_assign',
        forum: 'courseai_activity_forum',
        lesson: 'courseai_activity_lesson',
        url: 'courseai_activity_url',
        resource: 'courseai_activity_resource',
        page: 'courseai_activity_page',
        data: 'courseai_activity_data',
        glossary: 'courseai_activity_glossary',
        label: 'courseai_activity_resource',
        folder: 'courseai_activity_resource',
        wiki: null,
        workshop: null,
        scorm: null,
        imscp: null,
        feedback: null,
        choice: null,
        survey: null,
        h5pactivity: null,
        customcert: null,
        chat: null,
        lti: null,
    };

    const key = keyMap[type] || null;
    if (key && texts[key]) {
        return texts[key];
    }
    return type ? humanizeType(type) : texts.courseai_activity_default;
};
