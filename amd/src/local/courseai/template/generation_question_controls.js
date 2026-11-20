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
 * The simple parts of the questions of the AI: a yes or no, some text, a file, the chip of an activity and the
 * line that says why an answer cannot be sent yet.
 *
 * Each control draws one question and writes what the person answers in the answer sheet.
 *
 * @module     local_coursegen/local/courseai/template/generation_question_controls
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The longest text a person can write as an answer. */
export const TEXT_LIMIT = 2000;

/**
 * A node with a class and some text.
 *
 * @param {string} tag
 * @param {string} className
 * @param {string} [text]
 * @returns {HTMLElement}
 */
export const element = (tag, className, text = '') => {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text) {
        node.textContent = text;
    }
    return node;
};

/**
 * Show which of the two buttons is the answer.
 *
 * @param {Array<Object>} choices [{button, value}]
 * @param {Object} sheet
 * @param {string} id
 */
const paintToggle = (choices, sheet, id) => {
    choices.forEach(({button, value}) => {
        const chosen = sheet.values[id] === value;
        const checked = String(chosen);
        button.setAttribute('aria-checked', checked);
        button.classList.toggle('btn-primary', chosen);
        button.classList.toggle('btn-outline-secondary', !chosen);
    });
};

/**
 * One of the two buttons of a yes or no.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {boolean} value
 * @param {string} label
 * @returns {Object} {button, value}
 */
const toggleButton = (question, sheet, value, label) => {
    const button = element('button', 'btn btn-sm btn-outline-secondary', label);
    button.type = 'button';
    button.setAttribute('role', 'radio');
    button.dataset.value = String(value);
    button.addEventListener('click', () => sheet.set(question.id, value));
    return {button, value};
};

/**
 * Two buttons, yes and no.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} text
 * @returns {HTMLElement}
 */
export const toggleControl = (question, sheet, text) => {
    const group = element('div', 'cg-question-toggle');
    group.setAttribute('role', 'radiogroup');
    const yes = toggleButton(question, sheet, true, text.yes);
    const no = toggleButton(question, sheet, false, text.no);
    const choices = [yes, no];
    group.append(yes.button, no.button);
    sheet.onChange(() => paintToggle(choices, sheet, question.id));
    paintToggle(choices, sheet, question.id);
    return group;
};

/**
 * Keep what is written in a box, without the spaces around it.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {HTMLTextAreaElement} box
 */
const keepText = (question, sheet, box) => {
    const written = box.value.trim();
    sheet.set(question.id, written);
};

/**
 * A box for some text.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @returns {HTMLElement}
 */
export const textControl = (question, sheet) => {
    const box = document.createElement('textarea');
    box.className = 'form-control cg-question-text';
    box.rows = 2;
    box.maxLength = TEXT_LIMIT;
    let start = sheet.values[question.id];
    if (typeof start !== 'string') {
        start = '';
    }
    box.value = start;
    box.addEventListener('input', () => keepText(question, sheet, box));
    return box;
};

/**
 * Show the file picked, and the way to take it off only when there is one.
 *
 * @param {Object} parts {chosen, remove}
 * @param {Object} sheet
 * @param {string} id
 */
const paintFile = (parts, sheet, id) => {
    const value = sheet.values[id];
    let name = '';
    if (value) {
        name = value.filename;
    }
    parts.chosen.textContent = name;
    parts.remove.hidden = !value;
};

/**
 * Ask for a file and keep it as the answer when one was given.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Function} pickFile
 */
const attachFile = async(question, sheet, pickFile) => {
    const picked = await pickFile();
    if (picked && picked.draftitemid) {
        sheet.set(question.id, {draftitemid: picked.draftitemid, filename: picked.filename || ''});
    }
};

/**
 * A button that picks a file, and shows the one picked.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} text
 * @param {Function} pickFile Asks the person for a file: resolves {filename, draftitemid}, or null when they gave up.
 * @param {boolean} [removable] Whether the file picked can be taken off; a card that answers by picking has no use for it.
 * @returns {HTMLElement}
 */
export const fileControl = (question, sheet, text, pickFile, removable = true) => {
    const group = element('div', 'cg-question-file');
    const attach = element('button', 'btn btn-sm btn-outline-secondary', text.attach);
    attach.type = 'button';
    const chosen = element('span', 'cg-question-file-name');
    const remove = element('button', 'btn btn-sm btn-link', text.remove);
    remove.type = 'button';
    remove.hidden = true;
    const parts = {chosen, remove};
    attach.addEventListener('click', () => attachFile(question, sheet, pickFile));
    remove.addEventListener('click', () => sheet.set(question.id, null));
    sheet.onChange(() => paintFile(parts, sheet, question.id));
    paintFile(parts, sheet, question.id);
    group.append(attach);
    if (removable) {
        group.append(chosen, remove);
    }
    return group;
};

/**
 * The chip that names the activity a question is about.
 *
 * @param {Object} scope {uid, name, type}
 * @param {Object} text
 * @returns {HTMLElement}
 */
export const scopeChip = (scope, text) => {
    const chip = element('span', 'cg-question-scope');
    chip.setAttribute('title', scope.name);
    if (scope.type) {
        chip.dataset.type = scope.type;
    }
    const lead = element('span', 'sr-only', text.scope);
    const name = element('span', 'cg-question-scope-name', scope.name);
    chip.append(lead, name);
    return chip;
};

/**
 * The line under a question that says why its answer cannot be sent yet.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} text
 * @returns {HTMLElement}
 */
export const errorLine = (question, sheet, text) => {
    const line = element('span', 'cg-question-error');
    line.setAttribute('aria-live', 'polite');
    line.hidden = true;
    sheet.onChange(() => {
        const issue = sheet.issues[question.id];
        line.hidden = !issue;
        line.textContent = '';
        if (issue) {
            line.textContent = text[issue];
        }
    });
    return line;
};
