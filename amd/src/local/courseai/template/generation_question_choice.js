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
 * A question answered by choosing: one option (radio buttons) or several (checkboxes).
 *
 * A decision of the AI draws each option as a row with its label in bold, its description beneath and a badge on
 * the one the AI recommends, and may add a last row, Other, that opens a box for an answer in the person's own
 * words: that answer is sent as {other: text}. The questions of the older shape keep their plain radio buttons.
 *
 * @module     local_coursegen/local/courseai/template/generation_question_choice
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    element,
    fileControl,
    textControl,
    TEXT_LIMIT,
    toggleControl,
} from 'local_coursegen/local/courseai/template/generation_question_controls';

/** The class of a row that is the answer. */
const CHOSEN = 'cg-question-option--chosen';

/** The key of the card string that says an Other has nothing written. */
const OTHER_EMPTY = 'otherEmpty';

/**
 * The radio button or the checkbox of one row.
 *
 * @param {Object} question
 * @param {string} value
 * @param {number} index
 * @param {boolean} several
 * @returns {HTMLInputElement}
 */
const choiceInput = (question, value, index, several) => {
    const input = document.createElement('input');
    input.className = 'form-check-input';
    input.type = 'radio';
    if (several) {
        input.type = 'checkbox';
    }
    input.name = `cgq_${question.id}`;
    input.id = `cgq_${question.id}_${index}`;
    input.value = value;
    return input;
};

/**
 * An option in the markup the rest of the plugin (and Moodle) uses for a radio button or a checkbox.
 *
 * @param {Object} question
 * @param {Object} option
 * @param {number} index
 * @param {boolean} several
 * @returns {Object} {wrapper, input}
 */
const plainRow = (question, option, index, several) => {
    const wrapper = element('div', 'form-check mb-2');
    const input = choiceInput(question, option.value, index, several);
    const label = element('label', 'form-check-label', option.label);
    label.setAttribute('for', input.id);
    if (option.description) {
        const description = element('small', 'd-block text-muted', option.description);
        label.append(description);
    }
    wrapper.append(input, label);
    return {wrapper, input};
};

/**
 * An option of a decision: a row with the label in bold, the badge when it is the recommended one, the description.
 *
 * @param {Object} question
 * @param {Object} option
 * @param {number} index
 * @param {boolean} several
 * @param {Object} text
 * @returns {Object} {wrapper, input}
 */
const decisionRow = (question, option, index, several, text) => {
    const wrapper = element('div', 'form-check cg-question-option');
    const input = choiceInput(question, option.value, index, several);
    const label = element('label', 'form-check-label');
    label.setAttribute('for', input.id);
    const head = element('span', 'cg-question-option-head');
    const name = element('strong', 'cg-question-option-label', option.label);
    head.append(name);
    if (option.recommended === true) {
        const badge = element('span', 'cg-question-recommended', text.recommended);
        head.append(badge);
    }
    label.append(head);
    if (option.description) {
        const description = element('small', 'cg-question-option-desc', option.description);
        label.append(description);
    }
    wrapper.append(input, label);
    return {wrapper, input};
};

/**
 * The last row of a decision, Other, and the box it opens; the box sits after the row so typing in it never
 * chooses the row again.
 *
 * @param {Object} question
 * @param {number} index
 * @param {boolean} several
 * @param {Object} text
 * @returns {Object} {wrapper, input, holder, box}
 */
const otherRow = (question, index, several, text) => {
    const wrapper = element('div', 'form-check cg-question-option cg-question-option--other');
    const input = choiceInput(question, '', index, several);
    input.dataset.other = '1';
    const label = element('label', 'form-check-label');
    label.setAttribute('for', input.id);
    const name = element('strong', 'cg-question-option-label', text.other);
    label.append(name);
    wrapper.append(input, label);
    const box = document.createElement('textarea');
    box.className = 'form-control cg-question-other-text';
    box.rows = 2;
    box.maxLength = TEXT_LIMIT;
    box.setAttribute('placeholder', text.otherPlaceholder);
    box.setAttribute('aria-label', text.other);
    const holder = element('div', 'cg-question-other');
    holder.hidden = true;
    holder.append(box);
    return {wrapper, input, holder, box};
};

/**
 * The rows of one question, what is chosen in them, and the answer they make.
 */
class ChoiceControl {
    /**
     * @param {Object} question
     * @param {Object} sheet
     * @param {boolean} several Checkboxes rather than radio buttons.
     * @param {Object} setup {text, decision}
     */
    constructor(question, sheet, several, setup) {
        this.question = question;
        this.sheet = sheet;
        this.several = several;
        this.text = setup.text;
        this.decision = Boolean(setup.decision);
        this.rows = [];
        this.other = null;
        this.otherChosen = false;
        this.group = element('div', 'cg-question-options');
        this.group.setAttribute('role', 'radiogroup');
        if (several) {
            this.group.setAttribute('role', 'group');
        }
    }

    /**
     * Draw the rows and follow what the person chooses.
     *
     * @returns {HTMLElement}
     */
    build() {
        const options = this.question.options || [];
        options.forEach((option, index) => this.addOption(option, index));
        if (this.decision && this.question.allow_other === true) {
            this.addOther(options.length);
        }
        this.sheet.onChange(() => this.paint());
        this.paint();
        return this.group;
    }

    /**
     * Add the row of one option.
     *
     * @param {Object} option
     * @param {number} index
     */
    addOption(option, index) {
        let row = null;
        if (this.decision) {
            row = decisionRow(this.question, option, index, this.several, this.text);
        } else {
            row = plainRow(this.question, option, index, this.several);
        }
        const current = this.sheet.values[this.question.id];
        row.input.checked = this.isChosen(option.value, current);
        row.input.addEventListener('change', () => this.optionChanged(option.value));
        this.rows.push({wrapper: row.wrapper, input: row.input, value: option.value});
        this.group.append(row.wrapper);
    }

    /**
     * Add the Other row and its box.
     *
     * @param {number} index
     */
    addOther(index) {
        this.other = otherRow(this.question, index, this.several, this.text);
        this.other.input.addEventListener('change', () => this.otherChanged());
        this.other.box.addEventListener('input', () => this.commit());
        this.group.append(this.other.wrapper, this.other.holder);
    }

    /**
     * Whether an option is part of an answer.
     *
     * @param {string} value
     * @param {*} answer
     * @returns {boolean}
     */
    isChosen(value, answer) {
        if (this.several) {
            return Array.isArray(answer) && answer.includes(value);
        }
        return answer === value;
    }

    /**
     * An option was chosen (or, for checkboxes, ticked or unticked).
     *
     * @param {string} value
     */
    optionChanged(value) {
        if (this.several) {
            this.commit();
            return;
        }
        this.otherChosen = false;
        this.sheet.set(this.question.id, value);
    }

    /**
     * Other was chosen, or its checkbox ticked or unticked.
     */
    otherChanged() {
        this.otherChosen = true;
        if (this.several) {
            this.otherChosen = Boolean(this.other.input.checked);
        }
        this.commit();
        if (this.otherChosen) {
            this.other.box.focus();
        }
    }

    /**
     * @returns {string} What is written for Other, without the spaces around it.
     */
    otherText() {
        if (!this.other) {
            return '';
        }
        return this.other.box.value.trim();
    }

    /**
     * Write in the sheet the answer the rows make now.
     */
    commit() {
        const written = this.otherText();
        let issue = '';
        if (this.otherChosen && written === '') {
            issue = OTHER_EMPTY;
        }
        if (this.several) {
            const chosen = this.checkedValues();
            if (this.otherChosen && written !== '') {
                chosen.push({other: written});
            }
            this.sheet.set(this.question.id, chosen, issue);
            return;
        }
        if (!this.otherChosen) {
            // Writing in the box of an Other that is not chosen changes no answer.
            return;
        }
        let value = null;
        if (written !== '') {
            value = {other: written};
        }
        this.sheet.set(this.question.id, value, issue);
    }

    /**
     * @returns {Array<string>} The values of the boxes ticked, in the order of the options.
     */
    checkedValues() {
        const values = [];
        this.rows.forEach((row) => {
            if (row.input.checked) {
                values.push(row.value);
            }
        });
        return values;
    }

    /**
     * Mark the rows that are the answer, and show the box of Other only while Other is chosen.
     */
    paint() {
        if (!this.decision) {
            return;
        }
        const answer = this.sheet.values[this.question.id];
        this.rows.forEach((row) => this.paintRow(row, answer));
        if (this.other) {
            this.other.wrapper.classList.toggle(CHOSEN, this.otherChosen);
            this.other.holder.hidden = !this.otherChosen;
        }
    }

    /**
     * Mark one row as the answer, or not.
     *
     * @param {Object} row {wrapper, value}
     * @param {*} answer
     */
    paintRow(row, answer) {
        const chosen = this.isChosen(row.value, answer);
        row.wrapper.classList.toggle(CHOSEN, chosen);
    }
}

/**
 * One input per option: radio buttons for one choice, checkboxes for several.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {boolean} several
 * @param {Object} setup {text, decision}
 * @returns {HTMLElement}
 */
export const choiceControl = (question, sheet, several, setup) => {
    const control = new ChoiceControl(question, sheet, several, setup);
    return control.build();
};

/**
 * The control a question calls for, by kind; any other kind is asked as text rather than not asked.
 */
const CONTROLS = {
    toggle: (question, sheet, setup) => toggleControl(question, sheet, setup.text),
    single_choice: (question, sheet, setup) => choiceControl(question, sheet, false, setup),
    multi_choice: (question, sheet, setup) => choiceControl(question, sheet, true, setup),
    file: (question, sheet, setup) => fileControl(question, sheet, setup.text, setup.pickFile, !setup.compact),
    text: (question, sheet) => textControl(question, sheet),
};

/**
 * The control for one question.
 *
 * @param {Object} question
 * @param {Object} sheet
 * @param {Object} setup {text, pickFile, compact, decision}
 * @returns {HTMLElement}
 */
export const controlFor = (question, sheet, setup) => {
    let build = CONTROLS.text;
    if (Object.prototype.hasOwnProperty.call(CONTROLS, question.kind)) {
        build = CONTROLS[question.kind];
    }
    return build(question, sheet, setup);
};
