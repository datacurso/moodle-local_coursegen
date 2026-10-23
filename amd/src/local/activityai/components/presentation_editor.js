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
 * The editor of a Course Presentation the AI wrote, inside the chat to create an activity.
 *
 * The AI writes a draft; the person reads it as a presentation, changes what they want by hand or asks the AI to
 * change just one part, and only then is the activity made from what is on the screen.
 *
 * @module     local_coursegen/local/activityai/components/presentation_editor
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_strings as getStrings} from 'core/str';
import DOMPurify from 'local_coursegen/purify';

import * as repository from 'local_coursegen/local/activityai/repository';
import {loadActivityaiStrings} from 'local_coursegen/local/activityai/i18n';
import {createActivityFromJob} from 'local_coursegen/local/activityai/activity-creation';

const COMPONENT = 'local_coursegen';
const MIN_SLIDES = 3;
const MAX_SLIDES = 30;

/** The parts the AI can write again on its own, by the zone of the slide they are in. */
const PART_OF_ZONE = {title: 'title', body: 'body', image: 'image', question: 'question', slide: ''};

/** What the text of a slide may carry; the model's text is not trusted to carry more. */
const SAFE_HTML = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'blockquote', 'table', 'thead',
        'tbody', 'tr', 'th', 'td', 'a', 'span', 'div', 'code'],
    ALLOWED_ATTR: ['href'],
};

const STRING_KEYS = [
    'pe_drafting', 'pe_retry', 'pe_new_slide', 'pe_error_name', 'pe_error_slides', 'pe_error_title',
    'cpb_q_multi', 'cpb_q_tf', 'cpb_q_fill', 'pe_option', 'pe_save_error',
];

/**
 * Text as safe HTML.
 *
 * @param {string} html
 * @returns {string}
 */
const clean = (html) => {
    const purify = DOMPurify.default || DOMPurify;
    return purify.sanitize(String(html || ''), SAFE_HTML);
};

/**
 * Whether a value the model gave for "correct" means true.
 *
 * @param {boolean|string} value
 * @returns {boolean}
 */
const isTrue = (value) => value === true || String(value).toLowerCase() === 'true';

export default class extends BaseComponent {
    create() {
        this.name = 'local_coursegen_activityai_presentation_editor';
        this.model = null;
        this.zone = '';
        this.busy = '';
        this.errormessage = '';
        this.started = false;
        this.strings = {};
    }

    async stateReady() {
        const texts = await getStrings(STRING_KEYS.map((key) => ({key, component: COMPONENT})));
        STRING_KEYS.forEach((key, position) => {
            this.strings[key] = texts[position];
        });
        this.addEventListener(this.element, 'click', this._handleClick);
        this.addEventListener(this.element, 'input', this._handleInput);
        this.addEventListener(this.element, 'change', this._handleChange);
        this._sync();
    }

    getWatchers() {
        return [
            {watch: 'session.phase:updated', handler: this._sync},
            {watch: 'session.jobid:updated', handler: this._sync},
        ];
    }

    /**
     * The editor shows once the session of the editor is open.
     */
    async _sync() {
        const session = this.reactive.state.session;
        const editing = session.phase === 'editing' && Boolean(session.jobid);
        this.element.classList.toggle('d-none', !editing);
        if (editing && !this.started) {
            this.started = true;
            await this._draft();
        }
        if (!editing) {
            this.started = false;
            this.model = null;
        }
    }

    /**
     * Ask the AI for the slides and show them.
     */
    async _draft() {
        this.errormessage = '';
        this._showMessage(this.strings.pe_drafting, true);
        try {
            const draft = await repository.draftPresentation(this._job({instructions: ''}));
            this.model = {
                name: draft.name || '',
                intro: draft.intro || '',
                current: 0,
                slides: (draft.slides || []).map((slide) => this._normalised(slide)),
            };
            await this._render();
        } catch (error) {
            this._showMessage(this._messageOf(error), false, true);
        }
    }

    /**
     * @param {Object} extra
     * @returns {Object} The course and the job, with what the call adds.
     */
    _job(extra) {
        const state = this.reactive.state;
        return {courseid: state.page.courseid, jobid: String(state.session.jobid), ...extra};
    }

    /**
     * @param {Object} slide A slide as the service gives it.
     * @returns {Object} The slide with every field present.
     */
    _normalised(slide) {
        return {
            title: slide.title || '',
            body_html: slide.body_html || '',
            image_prompt: slide.image_prompt || '',
            video_url: slide.video_url || '',
            question: slide.question || null,
            uploaded_image: Boolean(slide.uploaded_image),
            image_slot: slide.image_slot || null,
        };
    }

    /**
     * @param {Error|Object} error
     * @returns {string} The message to show for an error of a call.
     */
    _messageOf(error) {
        return String((error && (error.message || error.error)) || this.strings.pe_save_error);
    }

    /**
     * Show a message instead of the editor: the AI is working, or something went wrong and can be tried again.
     *
     * @param {string} text
     * @param {boolean} working Show the spinner.
     * @param {boolean} retry Offer to try again.
     */
    _showMessage(text, working, retry = false) {
        const box = document.createElement('div');
        box.className = 'text-center py-5';
        if (working) {
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border text-primary';
            spinner.setAttribute('role', 'status');
            box.append(spinner);
        }
        const message = document.createElement('p');
        message.className = working ? 'mt-3 mb-0' : 'alert alert-danger text-left';
        message.textContent = text;
        box.append(message);
        if (retry) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-primary mt-2';
            button.dataset.action = 'local_coursegen/pe/redraft';
            button.textContent = this.strings.pe_retry;
            box.append(button);
        }
        this.element.replaceChildren(box);
    }

    /**
     * @returns {Object} What the template needs to draw the editor.
     */
    _context() {
        const model = this.model;
        const total = model.slides.length;
        const session = this.reactive.state.session;
        const context = {
            name: model.name,
            total,
            hasslides: total > 0,
            errormessage: this.errormessage,
            busy: this.busy,
            creating: this.busy === 'create',
            slides: model.slides.map((slide, index) => ({
                index, number: index + 1, title: slide.title || this.strings.pe_new_slide, current: index === model.current,
            })),
        };
        if (total > 0) {
            context.current = this._slideContext(model.slides[model.current], model.current, total, session);
        }
        return context;
    }

    /**
     * @param {Object} slide
     * @param {number} index
     * @param {number} total
     * @param {Object} session
     * @returns {Object} The slide in large, ready for the template.
     */
    _slideContext(slide, index, total, session) {
        const question = slide.question;
        const kind = question ? question.type : '';
        const labels = {
            multi_choice: this.strings.cpb_q_multi,
            true_false: this.strings.cpb_q_tf,
            fill_in_the_blanks: this.strings.cpb_q_fill,
        };
        const current = {
            number: index + 1,
            total,
            first: index === 0,
            last: index === total - 1,
            title: slide.title,
            body: clean(slide.body_html),
            showimage: Boolean(session.generateimages) || slide.uploaded_image || Boolean(slide.image_prompt),
            uploaded: slide.uploaded_image,
            imageprompt: slide.image_prompt,
            hasvideo: Boolean(slide.video_url),
            video: slide.video_url,
            hasquestion: Boolean(question),
            questionlabel: labels[kind] || '',
            qprompt: question ? question.prompt || '' : '',
            qmulti: kind === 'multi_choice',
            qtf: kind === 'true_false',
            qfill: kind === 'fill_in_the_blanks',
            qoptions: (question && question.options || []).map((option, position) => ({
                index: position, text: option.text || '', correct: isTrue(option.correct),
            })),
            qtrue: question ? isTrue(question.correct) : false,
            qtext: question ? question.text || '' : '',
        };
        for (const zone of Object.keys(PART_OF_ZONE)) {
            current['ai' + zone] = this.zone === zone;
            current['busy' + zone] = this.busy === zone;
        }
        return current;
    }

    /**
     * Draw the editor again from the model; what is typed is already in the model, so nothing is lost.
     */
    async _render() {
        const {html, js} = await Templates.renderForPromise('local_coursegen/presentation_editor', this._context());
        Templates.replaceNodeContents(this.element, html, js);
        this.element.querySelector("[data-region='local_coursegen/pe/aiinput']")?.focus();
    }

    /**
     * @returns {Object} The slide being edited.
     */
    _currentSlide() {
        return this.model.slides[this.model.current];
    }

    async _handleClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !this.element.contains(button) || !button.dataset.action.startsWith('local_coursegen/pe/')) {
            return;
        }
        if (button.tagName === 'SELECT') {
            return;
        }
        event.preventDefault();

        const action = button.dataset.action.replace('local_coursegen/pe/', '');
        if (action === 'redraft') {
            await this._draft();
            return;
        }
        if (!this.model || this.busy) {
            return;
        }
        const handled = await this._act(action, button);
        if (handled) {
            this.errormessage = '';
            await this._render();
        }
    }

    /**
     * Do what a button asks.
     *
     * @param {string} action
     * @param {HTMLElement} button
     * @returns {Promise<boolean>} Whether the editor has to be drawn again.
     */
    async _act(action, button) {
        const model = this.model;
        const slides = model.slides;
        const at = model.current;
        const moves = {
            select: () => {
                model.current = Number(button.dataset.index);
            },
            previous: () => {
                model.current = Math.max(0, at - 1);
            },
            next: () => {
                model.current = Math.min(slides.length - 1, at + 1);
            },
            add: () => {
                if (slides.length < MAX_SLIDES) {
                    slides.splice(at + 1, 0, this._normalised({title: this.strings.pe_new_slide, body_html: '<p></p>'}));
                    model.current = at + 1;
                }
            },
            duplicate: () => {
                if (slides.length < MAX_SLIDES) {
                    slides.splice(at + 1, 0, JSON.parse(JSON.stringify(slides[at])));
                    model.current = at + 1;
                }
            },
            remove: () => {
                slides.splice(at, 1);
                model.current = Math.max(0, Math.min(at, slides.length - 1));
            },
            up: () => {
                if (at > 0) {
                    [slides[at - 1], slides[at]] = [slides[at], slides[at - 1]];
                    model.current = at - 1;
                }
            },
            down: () => {
                if (at < slides.length - 1) {
                    [slides[at + 1], slides[at]] = [slides[at], slides[at + 1]];
                    model.current = at + 1;
                }
            },
            ai: () => {
                this.zone = button.dataset.zone;
            },
            aicancel: () => {
                this.zone = '';
            },
            removequestion: () => {
                slides[at].question = null;
            },
        };
        if (moves[action]) {
            moves[action]();
            if (['select', 'previous', 'next', 'add', 'remove', 'up', 'down', 'duplicate'].includes(action)) {
                this.zone = '';
            }
            return true;
        }
        if (action === 'aiapply') {
            await this._askAi();
        } else if (action === 'create') {
            await this._create();
        }
        return false;
    }

    /**
     * Ask the AI to change the part of the slide the bar is open for.
     */
    async _askAi() {
        const input = this.element.querySelector("[data-region='local_coursegen/pe/aiinput']");
        const instruction = String(input ? input.value : '').trim();
        if (!instruction) {
            input?.focus();
            return;
        }

        const zone = this.zone;
        const index = this.model.current;
        this.busy = zone;
        await this._render();
        try {
            const written = await repository.rewriteSlide(this._job({
                slides: this._cleanSlides(),
                index,
                instruction,
                part: PART_OF_ZONE[zone],
            }));
            this._apply(index, zone, this._normalised(written));
            this.zone = '';
            this.errormessage = '';
        } catch (error) {
            this.errormessage = this._messageOf(error);
        }
        this.busy = '';
        await this._render();
    }

    /**
     * Put what the AI wrote in the slide: the whole slide, or only the part that was asked for.
     *
     * @param {number} index
     * @param {string} zone
     * @param {Object} written
     */
    _apply(index, zone, written) {
        const slide = this.model.slides[index];
        const fields = {
            title: ['title'],
            body: ['body_html'],
            image: ['image_prompt'],
            question: ['question'],
            slide: ['title', 'body_html', 'image_prompt', 'video_url', 'question'],
        }[zone];
        fields.forEach((field) => {
            slide[field] = written[field];
        });
    }

    /**
     * @returns {Object[]} The slides as the service takes them, with their text made safe.
     */
    _cleanSlides() {
        return this.model.slides.map((slide) => ({
            title: slide.title,
            body_html: clean(slide.body_html),
            image_prompt: slide.image_prompt,
            video_url: slide.video_url,
            question: slide.question,
            uploaded_image: slide.uploaded_image,
            image_slot: slide.image_slot,
        }));
    }

    /**
     * Build the activity from the slides as they are and go to it.
     */
    async _create() {
        const model = this.model;
        const problem = this._problem();
        if (problem) {
            this.errormessage = problem;
            await this._render();
            return;
        }

        this.busy = 'create';
        this.errormessage = '';
        await this._render();
        try {
            await repository.buildPresentation(this._job({
                name: model.name.trim(),
                intro: model.intro,
                slides: this._cleanSlides(),
            }));
            const texts = await loadActivityaiStrings();
            await createActivityFromJob(this.reactive, texts);
        } catch (error) {
            this.errormessage = this._messageOf(error);
            Notification.exception(error);
        }
        this.busy = '';
        await this._render();
    }

    /**
     * @returns {string} What stops the activity from being made, or an empty string.
     */
    _problem() {
        if (!this.model.name.trim()) {
            return this.strings.pe_error_name;
        }
        if (this.model.slides.length < MIN_SLIDES) {
            return this.strings.pe_error_slides;
        }
        return this.model.slides.some((slide) => !slide.title.trim()) ? this.strings.pe_error_title : '';
    }

    /**
     * Keep the model in step with what is typed.
     *
     * @param {Event} event
     */
    _handleInput(event) {
        const target = event.target;
        if (!this.model) {
            return;
        }
        if (target.dataset.field === 'name') {
            this.model.name = target.value;
            return;
        }

        const slide = this._currentSlide();
        const field = target.dataset.field;
        if (field === 'title') {
            slide.title = target.textContent;
            this._refreshStrip(slide.title);
        } else if (field === 'body_html') {
            slide.body_html = target.innerHTML;
        } else if (field === 'image_prompt' || field === 'video_url') {
            slide[field] = target.value;
        } else if (target.dataset.question) {
            this._typedQuestion(slide, target);
        }
    }

    /**
     * Update the title of the current slide in the strip, without drawing the editor again.
     *
     * @param {string} title
     */
    _refreshStrip(title) {
        const label = this.element.querySelector('nav .btn-primary span:last-child');
        if (label) {
            label.textContent = title || this.strings.pe_new_slide;
        }
    }

    /**
     * What was typed in a field of the question.
     *
     * @param {Object} slide
     * @param {HTMLElement} target
     */
    _typedQuestion(slide, target) {
        const question = slide.question;
        if (!question) {
            return;
        }
        const what = target.dataset.question;
        if (what === 'prompt') {
            question.prompt = target.value;
        } else if (what === 'text') {
            question.text = target.value;
        } else if (what === 'option') {
            question.options[Number(target.dataset.option)].text = target.value;
        }
    }

    /**
     * The choices that are made with a change and not typing: the right option, true or false, a new question.
     *
     * @param {Event} event
     */
    async _handleChange(event) {
        const target = event.target;
        if (!this.model) {
            return;
        }
        const slide = this._currentSlide();
        if (target.dataset.action === 'local_coursegen/pe/addquestion' && target.value) {
            slide.question = this._newQuestion(target.value);
            await this._render();
        } else if (target.dataset.question === 'correct' && slide.question) {
            slide.question.options.forEach((option, position) => {
                option.correct = position === Number(target.dataset.option);
            });
        } else if (target.dataset.question === 'truefalse' && slide.question) {
            slide.question.correct = target.value === 'true';
        }
    }

    /**
     * @param {string} kind
     * @returns {Object} An empty question of a kind, ready to be written or asked of the AI.
     */
    _newQuestion(kind) {
        const question = {type: kind, prompt: ''};
        if (kind === 'multi_choice') {
            question.options = [1, 2, 3].map((number) => ({text: this.strings.pe_option + ' ' + number, correct: number === 1}));
        } else if (kind === 'true_false') {
            question.correct = true;
        } else {
            question.text = '';
        }
        return question;
    }
}
