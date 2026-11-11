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
 * The questions the AI asks before it starts, as one more message of the conversation.
 *
 * The service decides what to ask and how it is answered (a switch, one choice, several, a file, some text); this
 * module only draws what it is told and gives back what the person answered. A new kind of question is a new
 * control here and nothing else: the card, the buttons and the way the answers leave stay the same.
 *
 * @module     local_coursegen/local/courseai/template/generation_questions
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const STRINGS = [
    'courseai_template_questions_title',
    'courseai_template_questions_continue',
    'courseai_template_questions_skip',
    'courseai_template_questions_yes',
    'courseai_template_questions_no',
    'courseai_template_questions_attach',
    'courseai_template_questions_remove',
    'courseai_template_questions_required',
    'courseai_template_questions_skipped',
];

const CARD_ID = 'cgQuestionsCard';

/**
 * A node with a class and some text.
 *
 * @param {string} tag
 * @param {string} className
 * @param {string} [text]
 * @returns {HTMLElement}
 */
const element = (tag, className, text = '') => {
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
 * What the person answered so far, and the way to change it.
 *
 * @param {Array<Object>} questions
 * @returns {Object}
 */
const createAnswerSheet = (questions) => {
    const values = {};
    questions.forEach((question) => {
        if (question.default !== null && question.default !== undefined) {
            values[question.id] = question.default;
        }
    });
    const listeners = [];
    return {
        values,
        set(id, value) {
            if (value === null || value === undefined || (Array.isArray(value) && value.length === 0)) {
                delete values[id];
            } else {
                values[id] = value;
            }
            listeners.forEach((listener) => listener());
        },
        onChange: (listener) => listeners.push(listener),
        /** @returns {boolean} Whether every required question has an answer. */
        complete: () => questions.every((question) => !question.required || question.id in values),
        /** @returns {Array<Object>} The answers, in the order of the questions. */
        list: () => questions.filter((question) => question.id in values)
            .map((question) => ({question_id: question.id, value: values[question.id]})),
    };
};

/**
 * Two buttons, yes and no.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} text
 * @returns {HTMLElement}
 */
const toggleControl = (question, sheet, text) => {
    const group = element('div', 'cg-question-toggle');
    group.setAttribute('role', 'radiogroup');
    const buttons = [[true, text.yes], [false, text.no]].map(([value, label]) => {
        const button = element('button', 'btn btn-sm btn-outline-secondary', label);
        button.type = 'button';
        button.setAttribute('role', 'radio');
        button.dataset.value = String(value);
        button.addEventListener('click', () => sheet.set(question.id, value));
        group.append(button);
        return {button, value};
    });
    const paint = () => buttons.forEach(({button, value}) => {
        const chosen = sheet.values[question.id] === value;
        button.setAttribute('aria-checked', chosen ? 'true' : 'false');
        button.classList.toggle('btn-primary', chosen);
        button.classList.toggle('btn-outline-secondary', !chosen);
    });
    sheet.onChange(paint);
    paint();
    return group;
};

/**
 * One input per option: radio buttons for one choice, checkboxes for several.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {boolean} several
 * @returns {HTMLElement}
 */
const choiceControl = (question, sheet, several) => {
    const group = element('div', 'cg-question-options');
    group.setAttribute('role', several ? 'group' : 'radiogroup');
    question.options.forEach((option, index) => {
        // The markup the rest of the plugin (and Moodle) uses for a radio button or a checkbox, so these look like them.
        const wrapper = element('div', 'form-check mb-2');
        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = several ? 'checkbox' : 'radio';
        input.name = `cgq_${question.id}`;
        input.id = `cgq_${question.id}_${index}`;
        input.value = option.value;
        input.checked = several
            ? (sheet.values[question.id] || []).includes(option.value)
            : sheet.values[question.id] === option.value;
        input.addEventListener('change', () => {
            if (!several) {
                sheet.set(question.id, option.value);
                return;
            }
            const current = new Set(sheet.values[question.id] || []);
            if (input.checked) {
                current.add(option.value);
            } else {
                current.delete(option.value);
            }
            sheet.set(question.id, question.options.map((item) => item.value).filter((value) => current.has(value)));
        });
        const label = element('label', 'form-check-label', option.label);
        label.setAttribute('for', input.id);
        if (option.description) {
            label.append(element('small', 'd-block text-muted', option.description));
        }
        wrapper.append(input, label);
        group.append(wrapper);
    });
    return group;
};

/**
 * A box for some text.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @returns {HTMLElement}
 */
const textControl = (question, sheet) => {
    const box = document.createElement('textarea');
    box.className = 'form-control cg-question-text';
    box.rows = 2;
    box.maxLength = 2000;
    box.value = sheet.values[question.id] || '';
    box.addEventListener('input', () => sheet.set(question.id, box.value.trim()));
    return box;
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
const fileControl = (question, sheet, text, pickFile, removable = true) => {
    const group = element('div', 'cg-question-file');
    const attach = element('button', 'btn btn-sm btn-outline-secondary', text.attach);
    attach.type = 'button';
    const chosen = element('span', 'cg-question-file-name');
    const remove = element('button', 'btn btn-sm btn-link', text.remove);
    remove.type = 'button';
    remove.hidden = true;
    const paint = () => {
        const value = sheet.values[question.id];
        chosen.textContent = value ? value.filename : '';
        remove.hidden = !value;
    };
    attach.addEventListener('click', async() => {
        const picked = await pickFile();
        if (picked && picked.draftitemid) {
            sheet.set(question.id, {draftitemid: picked.draftitemid, filename: picked.filename || ''});
        }
    });
    remove.addEventListener('click', () => sheet.set(question.id, null));
    sheet.onChange(paint);
    paint();
    group.append(attach);
    if (removable) {
        group.append(chosen, remove);
    }
    return group;
};

/**
 * The control a question calls for.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} text
 * @param {Function} pickFile
 * @param {boolean} [compact] Whether the card has this one question and answers as soon as it is chosen.
 * @returns {HTMLElement}
 */
const controlFor = (question, sheet, text, pickFile, compact = false) => {
    switch (question.kind) {
        case 'toggle':
            return toggleControl(question, sheet, text);
        case 'single_choice':
            return choiceControl(question, sheet, false);
        case 'multi_choice':
            return choiceControl(question, sheet, true);
        case 'file':
            return fileControl(question, sheet, text, pickFile, !compact);
        default:
            return textControl(question, sheet);
    }
};

/**
 * What the person said, in one line per answered question, for the conversation.
 *
 * @param {Array<Object>} questions
 * @param {Object} reply {skipped, answers}
 * @param {Object} text The card's strings.
 * @returns {string}
 */
export const summarize = (questions, reply, text) => {
    if (reply.skipped || reply.answers.length === 0) {
        return text.skipped;
    }
    const byId = Object.fromEntries(questions.map((question) => [question.id, question]));
    const lines = reply.answers.map(({question_id: id, value}) => {
        const question = byId[id];
        const labels = Object.fromEntries((question.options || []).map((option) => [option.value, option.label]));
        let shown = value;
        if (typeof value === 'boolean') {
            shown = value ? text.yes : text.no;
        } else if (Array.isArray(value)) {
            shown = value.map((item) => labels[item] || item).join(', ');
        } else if (value && typeof value === 'object') {
            shown = value.filename;
        } else if (labels[value]) {
            shown = labels[value];
        }
        return `${question.prompt} ${shown}`;
    });
    return lines.join('\n');
};

/**
 * The card's strings, by short name.
 *
 * @returns {Promise<Object>}
 */
export const getQuestionStrings = async() => {
    const values = await getStrings(STRINGS.map((key) => ({key, component: 'local_coursegen'})));
    const [title, next, skip, yes, no, attach, remove, required, skipped] = values;
    return {title, next, skip, yes, no, attach, remove, required, skipped};
};

/**
 * Show the questions in the conversation and resolve with the answers.
 *
 * @param {Object} data The event of the service: {questions, can_skip}.
 * @param {Object} options
 * @param {Function} options.pickFile Asks the person for a file: resolves {filename, draftitemid} or null.
 * @param {HTMLElement} [options.host] Where the card goes; the conversation by default.
 * @returns {Promise<Object>} {skipped, answers: [{question_id, value}]}
 */
export const askQuestions = async(data, {pickFile, host} = {}) => {
    const text = await getQuestionStrings();
    const questions = data.questions || [];
    const sheet = createAnswerSheet(questions);
    document.getElementById(CARD_ID)?.remove();

    // One question with a short answer (a yes or a no, a file) is answered at once and shows nothing else: no title,
    // no explanation, no "continue". Anything longer keeps the form with its buttons.
    const single = questions.length === 1 ? questions[0] : null;
    const compact = single !== null && (single.kind === 'toggle' || single.kind === 'file');
    const canSkip = data.can_skip !== false;

    // The same markup as any other turn of the thread, so the questions read as one more message of the left
    // column and not as a form over the page.
    const card = element('div', 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai cg-question-card');
    card.id = CARD_ID;
    card.setAttribute('role', 'group');
    card.setAttribute('aria-label', text.title);
    const bar = element('span', 'cg-log-bar');
    bar.setAttribute('aria-hidden', 'true');
    const body = element('span', 'cg-log-body');
    const message = element('span', 'cg-log-msg');
    if (!compact) {
        message.append(element('strong', 'd-block mb-2', text.title));
    }

    questions.forEach((question) => {
        const block = element('fieldset', 'cg-question');
        block.dataset.question = question.id;
        block.append(element('legend', 'cg-question-prompt', question.prompt));
        if (question.hint) {
            block.append(element('span', 'cg-question-hint', question.hint));
        }
        block.append(controlFor(question, sheet, text, pickFile || (async() => null), compact));
        message.append(block);
    });

    const buttons = element('span', 'cg-card-actions mt-2');
    let next = null;
    if (!compact) {
        next = element('button', 'cg-card-button cg-card-button--primary', text.next);
        next.type = 'button';
        buttons.append(next);
    }
    let skip = null;
    // A yes or no is its own way out; a file question needs the way to go on without one.
    if (canSkip && !(compact && single.kind === 'toggle')) {
        skip = element('button', 'cg-card-button', text.skip);
        skip.type = 'button';
        buttons.append(skip);
    }
    const note = element('span', 'cg-question-required', '');
    note.hidden = true;
    message.append(note);
    if (buttons.children.length) {
        message.append(buttons);
    }
    body.append(message);
    card.append(bar, body);

    const refresh = () => {
        const ready = sheet.complete();
        if (next) {
            next.disabled = !ready;
        }
        note.hidden = ready || compact;
        note.textContent = ready ? '' : text.required;
    };
    sheet.onChange(refresh);
    refresh();

    const target = host || document.getElementById('cgLog') || document.body;
    target.append(card);
    card.scrollIntoView({block: 'nearest'});

    return new Promise((resolve) => {
        const finish = (reply) => {
            card.remove();
            resolve(reply);
        };
        if (next) {
            next.addEventListener('click', () => {
                if (sheet.complete()) {
                    finish({skipped: false, answers: sheet.list()});
                }
            });
        }
        if (compact) {
            // The answer is given by choosing it: nothing else to press.
            sheet.onChange(() => {
                if (single.id in sheet.values) {
                    finish({skipped: false, answers: sheet.list()});
                }
            });
        }
        if (skip) {
            skip.addEventListener('click', () => finish({skipped: true, answers: []}));
        }
    });
};
