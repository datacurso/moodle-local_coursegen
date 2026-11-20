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
 * The questions of the AI as data: whether they come in the shape of a decision, the activity they are about,
 * what the person answered, and how those answers read in the conversation.
 *
 * @module     local_coursegen/local/courseai/template/generation_question_sheet
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The fields of a question that only a decision of the AI has. */
const DECISION_FIELDS = ['scope', 'allow_other'];

/**
 * Whether a value is no answer at all.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isEmpty = (value) => {
    if (value === null || value === undefined) {
        return true;
    }
    return Array.isArray(value) && value.length === 0;
};

/**
 * The answers given so far, what still keeps them from being sent, and the way to change them.
 */
export class AnswerSheet {
    /**
     * @param {Array<Object>} questions
     */
    constructor(questions) {
        this.questions = questions;
        this.values = {};
        this.issues = {};
        this.listeners = [];
        questions.forEach((question) => this.applyDefault(question));
    }

    /**
     * Start a question answered with its default, when it has one.
     *
     * @param {Object} question
     */
    applyDefault(question) {
        if (question.default !== null && question.default !== undefined) {
            this.values[question.id] = question.default;
        }
    }

    /**
     * Change the answer to a question, and what keeps it from being sent.
     *
     * @param {string} id
     * @param {*} value An empty value (null, undefined, an empty list) leaves the question unanswered.
     * @param {string} [issue] The key of the card string that says why the answer cannot be sent yet; '' when it can.
     */
    set(id, value, issue = '') {
        if (isEmpty(value)) {
            delete this.values[id];
        } else {
            this.values[id] = value;
        }
        if (issue) {
            this.issues[id] = issue;
        } else {
            delete this.issues[id];
        }
        this.listeners.forEach((listener) => listener());
    }

    /**
     * Be told of every change.
     *
     * @param {Function} listener
     */
    onChange(listener) {
        this.listeners.push(listener);
    }

    /**
     * @returns {boolean} Whether a required question has no answer yet.
     */
    missing() {
        return this.questions.some((question) => question.required && !(question.id in this.values));
    }

    /**
     * @returns {boolean} Whether an answer was started in a way that cannot be sent (an Other with nothing written).
     */
    blocked() {
        return Object.keys(this.issues).length > 0;
    }

    /**
     * @returns {boolean} Whether the answers can be sent.
     */
    complete() {
        return !this.missing() && !this.blocked();
    }

    /**
     * @returns {Array<Object>} The answers, in the order of the questions: [{question_id, value}].
     */
    list() {
        const answers = [];
        this.questions.forEach((question) => {
            if (question.id in this.values) {
                answers.push({question_id: question.id, value: this.values[question.id]});
            }
        });
        return answers;
    }
}

/**
 * The questions by id.
 *
 * @param {Array<Object>} questions
 * @returns {Object}
 */
const indexById = (questions) => {
    const byId = {};
    questions.forEach((question) => {
        byId[question.id] = question;
    });
    return byId;
};

/**
 * The label of each option of a question, by value.
 *
 * @param {Object} question
 * @returns {Object}
 */
const optionLabels = (question) => {
    const labels = {};
    const options = question.options || [];
    options.forEach((option) => {
        labels[option.value] = option.label;
    });
    return labels;
};

/**
 * One answered item in words: the label of its option, the words written for Other, the name of a file.
 *
 * @param {*} item
 * @param {Object} labels
 * @returns {string}
 */
const shownItem = (item, labels) => {
    if (item && typeof item === 'object') {
        if ('other' in item) {
            return String(item.other);
        }
        return item.filename || '';
    }
    if (labels[item]) {
        return labels[item];
    }
    return String(item);
};

/**
 * A whole answer in words.
 *
 * @param {*} value
 * @param {Object} labels
 * @param {Object} text The card's strings.
 * @returns {string}
 */
const shownValue = (value, labels, text) => {
    if (typeof value === 'boolean') {
        if (value) {
            return text.yes;
        }
        return text.no;
    }
    if (Array.isArray(value)) {
        const items = value.map((item) => shownItem(item, labels));
        return items.join(', ');
    }
    return shownItem(value, labels);
};

/**
 * One line of the summary: the question and what was answered; '' for an answer to a question it does not know.
 *
 * @param {Object} answer {question_id, value}
 * @param {Object} byId
 * @param {Object} text
 * @returns {string}
 */
const summaryLine = (answer, byId, text) => {
    const question = byId[answer.question_id];
    if (!question) {
        return '';
    }
    const labels = optionLabels(question);
    const shown = shownValue(answer.value, labels, text);
    return `${question.prompt} ${shown}`;
};

/**
 * The lines of the summary, one per answer to a question it knows.
 *
 * @param {Array<Object>} answers
 * @param {Object} byId
 * @param {Object} text
 * @returns {Array<string>}
 */
const summaryLines = (answers, byId, text) => {
    const lines = [];
    answers.forEach((answer) => {
        const line = summaryLine(answer, byId, text);
        if (line) {
            lines.push(line);
        }
    });
    return lines;
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
    const byId = indexById(questions);
    const lines = summaryLines(reply.answers, byId, text);
    if (lines.length === 0) {
        return text.skipped;
    }
    return lines.join('\n');
};

/**
 * Whether an option is marked, either way, as recommended.
 *
 * @param {Object} option
 * @returns {boolean}
 */
const saysRecommended = (option) => Boolean(option) && typeof option === 'object' && 'recommended' in option;

/**
 * Whether a question has a field only a decision has.
 *
 * @param {Object} question
 * @returns {boolean}
 */
const hasDecisionField = (question) => DECISION_FIELDS.some((field) => field in question);

/**
 * Whether a question comes in the shape of a decision.
 *
 * @param {Object} question
 * @returns {boolean}
 */
const isDecisionQuestion = (question) => {
    if (hasDecisionField(question)) {
        return true;
    }
    const options = question.options || [];
    return options.some(saysRecommended);
};

/**
 * Whether the event is a decision of the AI rather than a question of the older shape.
 *
 * @param {Object} data
 * @returns {boolean}
 */
export const isDecision = (data) => {
    if (data.phase) {
        return true;
    }
    const questions = data.questions || [];
    return questions.some(isDecisionQuestion);
};

/**
 * What tells the activity of a question apart: its uid, else its name; '' when it is about no named activity.
 *
 * @param {Object} question
 * @returns {string}
 */
export const scopeKey = (question) => {
    const scope = question.scope;
    if (!scope || !scope.name) {
        return '';
    }
    return String(scope.uid || scope.name);
};

/**
 * Whether every key is the first one.
 *
 * @param {Array<string>} keys
 * @returns {boolean}
 */
const allSame = (keys) => keys.every((key) => key === keys[0]);

/**
 * The activity every question is about, when they are all about the same one; null otherwise.
 *
 * @param {Array<Object>} questions
 * @returns {Object|null}
 */
export const sharedScope = (questions) => {
    const keys = questions.map(scopeKey);
    if (keys.length === 0 || keys[0] === '' || !allSame(keys)) {
        return null;
    }
    return questions[0].scope;
};
