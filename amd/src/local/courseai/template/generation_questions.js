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
 * The questions the AI asks, before it starts or in the middle of a run, as one more message of the conversation.
 *
 * The service decides what to ask and how it is answered (a switch, one choice, several, a file, some text); this
 * module only draws what it is told and gives back what the person answered. A new kind of question is a new
 * control and nothing else: the card, the buttons and the way the answers leave stay the same.
 *
 * A decision of the AI (a question that says its phase, the activity it is about, the option it recommends or that
 * takes an answer in other words) gets a header that says a decision is needed and names the activity, and option
 * rows with their descriptions; questions of the older shape are drawn as they always were.
 *
 * @module     local_coursegen/local/courseai/template/generation_questions
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {currentFeed} from 'local_coursegen/local/courseai/template/thread_feed';
import {
    AnswerSheet,
    isDecision,
    scopeKey,
    sharedScope,
} from 'local_coursegen/local/courseai/template/generation_question_sheet';
import {controlFor} from 'local_coursegen/local/courseai/template/generation_question_choice';
import {element, errorLine, scopeChip} from 'local_coursegen/local/courseai/template/generation_question_controls';

export {summarize} from 'local_coursegen/local/courseai/template/generation_question_sheet';

/** The card's strings: the short name each one goes by, and its key. */
const STRINGS = {
    title: 'courseai_template_questions_title',
    next: 'courseai_template_questions_continue',
    skip: 'courseai_template_questions_skip',
    yes: 'courseai_template_questions_yes',
    no: 'courseai_template_questions_no',
    attach: 'courseai_template_questions_attach',
    remove: 'courseai_template_questions_remove',
    required: 'courseai_template_questions_required',
    skipped: 'courseai_template_questions_skipped',
    titleGeneration: 'courseai_template_questions_title_generation',
    send: 'courseai_template_questions_send',
    recommended: 'courseai_template_questions_recommended',
    other: 'courseai_template_questions_other',
    otherPlaceholder: 'courseai_template_questions_other_placeholder',
    otherEmpty: 'courseai_template_questions_other_empty',
    scope: 'courseai_template_questions_scope',
};

const CARD_ID = 'cgQuestionsCard';

/** The markup of any other turn of the thread, so the questions read as one more message of the left column. */
const CARD_CLASSES = 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai cg-question-card';

/** The kinds that, alone in a card, are answered as soon as they are chosen. */
const COMPACT_KINDS = ['toggle', 'file'];

/**
 * A picker for when the page gave none: the person gives up at once.
 *
 * @returns {Promise<null>}
 */
const noFile = async() => null;

/**
 * The card of one set of questions: what it draws, and the answer it resolves with.
 */
class QuestionCard {
    /**
     * @param {Object} data The event of the service: {questions, can_skip, phase, request_id}.
     * @param {Object} text The card's strings.
     * @param {Function} pickFile
     */
    constructor(data, text, pickFile) {
        this.text = text;
        this.questions = data.questions || [];
        this.sheet = new AnswerSheet(this.questions);
        this.decision = isDecision(data);
        this.generation = data.phase === 'generation';
        this.single = null;
        if (this.questions.length === 1) {
            this.single = this.questions[0];
        }
        // One question with a short answer (a yes or a no, a file) is answered at once: no Continue.
        this.compact = this.single !== null && COMPACT_KINDS.includes(this.single.kind);
        this.canSkip = data.can_skip !== false;
        this.shared = null;
        if (this.decision) {
            this.shared = sharedScope(this.questions);
        }
        this.setup = {text, pickFile, compact: this.compact, decision: this.decision};
        this.card = null;
        this.next = null;
        this.skip = null;
        this.note = null;
    }

    /**
     * @returns {string} What the card says it is.
     */
    title() {
        if (this.generation) {
            return this.text.titleGeneration;
        }
        return this.text.title;
    }

    /**
     * Draw the card.
     *
     * @returns {HTMLElement}
     */
    build() {
        const card = element('div', CARD_CLASSES);
        if (this.decision) {
            card.classList.add('cg-question-card--decision');
        }
        card.id = CARD_ID;
        card.setAttribute('role', 'group');
        card.setAttribute('aria-label', this.title());
        const bar = element('span', 'cg-log-bar');
        bar.setAttribute('aria-hidden', 'true');
        const body = element('span', 'cg-log-body');
        const message = element('span', 'cg-log-msg');
        const header = this.header();
        if (header) {
            message.append(header);
        }
        this.questions.forEach((question) => this.appendBlock(message, question));
        this.note = element('span', 'cg-question-required');
        this.note.hidden = true;
        message.append(this.note);
        const actions = this.actions();
        if (actions.children.length) {
            message.append(actions);
        }
        body.append(message);
        card.append(bar, body);
        this.sheet.onChange(() => this.refresh());
        this.refresh();
        this.card = card;
        return card;
    }

    /**
     * The top of the card: the decision needed and the activity, or the title of the older shape.
     *
     * @returns {HTMLElement|null}
     */
    header() {
        if (!this.decision) {
            if (this.compact) {
                return null;
            }
            return element('strong', 'd-block mb-2', this.title());
        }
        const head = element('span', 'cg-question-head');
        const title = element('strong', 'cg-question-title', this.title());
        head.append(title);
        if (this.shared) {
            const chip = scopeChip(this.shared, this.text);
            head.append(chip);
        }
        return head;
    }

    /**
     * Add the block of one question to the message.
     *
     * @param {HTMLElement} message
     * @param {Object} question
     */
    appendBlock(message, question) {
        const block = this.block(question);
        message.append(block);
    }

    /**
     * One question: its prompt, its context, its control and, for a decision, what keeps its answer back.
     *
     * @param {Object} question
     * @returns {HTMLElement}
     */
    block(question) {
        const block = element('fieldset', 'cg-question');
        block.dataset.question = question.id;
        const legend = this.legend(question);
        block.append(legend);
        if (question.hint) {
            const hint = element('span', 'cg-question-hint', question.hint);
            block.append(hint);
        }
        const control = controlFor(question, this.sheet, this.setup);
        block.append(control);
        if (this.decision) {
            const error = errorLine(question, this.sheet, this.text);
            block.append(error);
        }
        return block;
    }

    /**
     * The prompt of a question, with the activity it is about when the header does not name it already.
     *
     * @param {Object} question
     * @returns {HTMLElement}
     */
    legend(question) {
        const legend = element('legend', 'cg-question-prompt');
        if (!this.decision || this.shared || scopeKey(question) === '') {
            legend.textContent = question.prompt;
            return legend;
        }
        const chip = scopeChip(question.scope, this.text);
        const prompt = element('span', 'cg-question-prompt-text', question.prompt);
        legend.append(chip, prompt);
        return legend;
    }

    /**
     * The buttons: send the answers (Continue at the start), and go on without answering when that is allowed.
     *
     * @returns {HTMLElement}
     */
    actions() {
        const buttons = element('span', 'cg-card-actions mt-2');
        if (!this.compact) {
            let label = this.text.next;
            if (this.generation) {
                label = this.text.send;
            }
            this.next = element('button', 'cg-card-button cg-card-button--primary', label);
            this.next.type = 'button';
            buttons.append(this.next);
        }
        // A yes or no is its own way out; a file question needs the way to go on without one.
        const answersItself = this.compact && this.single.kind === 'toggle';
        if (this.canSkip && !answersItself) {
            this.skip = element('button', 'cg-card-button', this.text.skip);
            this.skip.type = 'button';
            buttons.append(this.skip);
        }
        return buttons;
    }

    /**
     * Let the answers go only when they can be sent, and say when a required one is missing.
     */
    refresh() {
        const missing = this.sheet.missing();
        if (this.next) {
            this.next.disabled = !this.sheet.complete();
        }
        this.note.hidden = !missing || this.compact;
        this.note.textContent = '';
        if (missing) {
            this.note.textContent = this.text.required;
        }
    }

    /**
     * Resolve with the reply once the person answers or goes on without answering.
     *
     * @returns {Promise<Object>} {skipped, answers}
     */
    wait() {
        return new Promise((resolve) => this.listen(resolve));
    }

    /**
     * Follow the buttons, and the answer itself on a card that answers by choosing.
     *
     * @param {Function} resolve
     */
    listen(resolve) {
        if (this.next) {
            this.next.addEventListener('click', () => this.submit(resolve));
        }
        if (this.compact) {
            this.sheet.onChange(() => this.answerAtOnce(resolve));
        }
        if (this.skip) {
            this.skip.addEventListener('click', () => this.finish(resolve, {skipped: true, answers: []}));
        }
    }

    /**
     * Send the answers, when they can be sent.
     *
     * @param {Function} resolve
     */
    submit(resolve) {
        if (!this.sheet.complete()) {
            return;
        }
        const answers = this.sheet.list();
        this.finish(resolve, {skipped: false, answers});
    }

    /**
     * On a card that answers by choosing, the answer is given as soon as there is one.
     *
     * @param {Function} resolve
     */
    answerAtOnce(resolve) {
        if (this.single.id in this.sheet.values) {
            const answers = this.sheet.list();
            this.finish(resolve, {skipped: false, answers});
        }
    }

    /**
     * Take the card off the conversation and give the reply.
     *
     * @param {Function} resolve
     * @param {Object} reply
     */
    finish(resolve, reply) {
        this.card.remove();
        resolve(reply);
    }
}

/**
 * Pair each short name with its string.
 *
 * @param {Array<string>} names
 * @param {Array<string>} values
 * @returns {Object}
 */
const byName = (names, values) => {
    const text = {};
    names.forEach((name, index) => {
        text[name] = values[index];
    });
    return text;
};

/**
 * The card's strings, by short name.
 *
 * @returns {Promise<Object>}
 */
export const getQuestionStrings = async() => {
    const names = Object.keys(STRINGS);
    const requests = names.map((name) => ({key: STRINGS[name], component: 'local_coursegen'}));
    const values = await getStrings(requests);
    return byName(names, values);
};

/**
 * Show the questions in the conversation and resolve with the answers.
 *
 * @param {Object} data The event of the service: {questions, can_skip, phase, request_id}.
 * @param {Object} options
 * @param {Function} options.pickFile Asks the person for a file: resolves {filename, draftitemid} or null.
 * @param {HTMLElement} [options.host] Where the card goes; the conversation by default.
 * @returns {Promise<Object>} {skipped, answers: [{question_id, value}]}
 */
export const askQuestions = async(data, {pickFile, host} = {}) => {
    const text = await getQuestionStrings();
    document.getElementById(CARD_ID)?.remove();
    let picker = pickFile;
    if (!picker) {
        picker = noFile;
    }
    const view = new QuestionCard(data, text, picker);
    const card = view.build();
    let target = host;
    if (!target) {
        const feed = currentFeed();
        target = feed || document.body;
    }
    target.append(card);
    card.scrollIntoView({block: 'nearest'});
    return view.wait();
};
