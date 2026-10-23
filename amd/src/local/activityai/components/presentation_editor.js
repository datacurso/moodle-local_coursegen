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
 * The editor of a Course Presentation, inside the chat to create an activity.
 *
 * Every part of a slide has a line to tell the AI what to write there. The AI writes the whole presentation from the
 * topic and those lines, or only one part when that part's line is applied; what it wrote can also be edited by hand,
 * and the activity is made from what is on the screen.
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

import {pickFile} from 'local_coursegen/local/activityai/filepicker';
import * as repository from 'local_coursegen/local/activityai/repository';
import {loadActivityaiStrings} from 'local_coursegen/local/activityai/i18n';
import {createActivityFromJob} from 'local_coursegen/local/activityai/activity-creation';

const COMPONENT = 'local_coursegen';
const MIN_SLIDES = 3;
const MAX_SLIDES = 30;
const KIND = 'course_presentation';
const ZONES = ['title', 'body', 'image', 'question', 'slide'];

/** The part of the slide the AI writes again for each zone; the whole slide is the empty one. */
const PART_OF_ZONE = {title: 'title', body: 'body', image: 'image', question: 'question', slide: ''};

/** The names of the questions, as the AI is told. */
const QUESTION_NAMES = {multi_choice: 'multiple choice', true_false: 'true or false', fill_in_the_blanks: 'fill in the blanks'};

/** What the text of a slide may carry; the model's text is not trusted to carry more. */
const SAFE_HTML = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'blockquote', 'table', 'thead',
        'tbody', 'tr', 'th', 'td', 'a', 'span', 'div', 'code'],
    ALLOWED_ATTR: ['href'],
};

const STRING_KEYS = [
    'pe_error_name', 'pe_error_slides', 'pe_error_title', 'pe_error_brief', 'pe_regenerate_title', 'pe_regenerate_body',
    'pe_regenerate_yes', 'pe_slide_label', 'pe_scope_slide', 'pe_scope_title', 'pe_scope_body', 'pe_scope_image',
    'pe_scope_question', 'pe_instr_title', 'pe_instr_body', 'pe_instr_image', 'pe_instr_question', 'pe_instr_slide',
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
 * @param {string} html
 * @returns {string} The text of some HTML, without its tags.
 */
const plain = (html) => String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * Whether a value the model gave for "correct" means true.
 *
 * @param {boolean|string} value
 * @returns {boolean}
 */
const isTrue = (value) => value === true || String(value).toLowerCase() === 'true';

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_presentation_editor';
        this.footer = descriptor.footer || null;
        this.model = null;
        this.busy = '';
        this.topic = '';
        this.count = '';
        this.sentFile = null;
        this.errormessage = '';
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
            {watch: 'session.typestep:updated', handler: this._sync},
            {watch: 'session.h5ptype:updated', handler: this._sync},
            {watch: 'upload:updated', handler: this._refreshFile},
        ];
    }

    /**
     * The editor is what the person gets as soon as Course Presentation is chosen.
     */
    async _sync() {
        const session = this.reactive.state.session;
        const visible = session.typestep === 'prompt' && session.activitytype === 'h5pactivity' && session.h5ptype === KIND;
        this.element.classList.toggle('d-none', !visible);
        this._syncFooter(visible);
        this.element.closest('.modal')?.classList.toggle('local_coursegen_pe_active', visible);
        if (visible && !this.model) {
            this.model = {name: '', intro: '', current: 0, slides: []};
            await this._render();
        } else if (!visible && !session.jobid) {
            this.model = null;
            this.topic = '';
            this.count = '';
        }
    }

    /**
     * The field of the chat and the general choice about images are not used here: the AI is told what to do from the
     * editor and images are decided slide by slide.
     *
     * @param {boolean} hidden
     */
    _syncFooter(hidden) {
        const footer = this.footer;
        const radios = footer ? [...footer.querySelectorAll("input[name='generate_images']")] : [];
        const nodes = [footer?.querySelector('.ai-prompt-container'), ...radios.map((radio) => radio.closest('label'))];
        nodes.filter(Boolean).forEach((node) => {
            if (hidden) {
                node.style.setProperty('display', 'none', 'important');
            } else {
                node.style.removeProperty('display');
            }
        });
    }

    /**
     * Start the job the AI works on, once, the first time the AI is needed.
     */
    async _ensureSession() {
        const state = this.reactive.state;
        if (state.session.jobid) {
            return;
        }
        await this.reactive.dispatch('setGenerateImages', 1);
        await this.reactive.dispatch('beginPresentation', {prompt: this.topic || this.model.name || KIND});
        if (!state.session.jobid) {
            throw new Error(this.strings.pe_save_error);
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
     * @param {Object} slide A slide as the service gives it, or nothing for an empty one.
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
            instr: {title: '', body: '', image: '', question: '', slide: ''},
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
     * @returns {boolean} Whether anything was written in the slides.
     */
    _hasContent() {
        return this.model.slides.some((slide) => slide.title.trim() || plain(slide.body_html) || slide.question);
    }

    /**
     * Show the file of the chat in the editor.
     */
    _refreshFile() {
        if (this.model) {
            this._render();
        }
    }

    /**
     * @returns {Object} What the template needs to draw the editor.
     */
    _context() {
        const model = this.model;
        const total = model.slides.length;
        const context = {
            name: model.name,
            topic: this.topic,
            count: this.count,
            hasslides: total > 0,
            total,
            errormessage: this.errormessage,
            busy: this.busy,
            creating: this.busy === 'create',
            busyall: this.busy === 'all',
            hasfile: Boolean(this.reactive.state.upload.draftitemid),
            filename: this.reactive.state.upload.filename,
            slides: model.slides.map((slide, index) => ({
                index,
                number: index + 1,
                title: slide.title || this.strings.pe_slide_label + ' ' + (index + 1),
                current: index === model.current,
            })),
        };
        if (total > 0) {
            context.current = this._slideContext(model.slides[model.current], model.current, total);
        }
        return context;
    }

    /**
     * @param {Object} slide
     * @param {number} index
     * @param {number} total
     * @returns {Object} The slide in large, ready for the template.
     */
    _slideContext(slide, index, total) {
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
            busy: this.busy !== '' && this.busy !== 'create' && this.busy !== 'all',
        };
        ZONES.forEach((zone) => {
            current['ai' + zone] = {
                zone,
                value: slide.instr[zone],
                placeholder: this.strings['pe_instr_' + zone],
                label: this.strings['pe_scope_' + zone],
                working: this.busy === zone,
            };
        });
        return current;
    }

    /**
     * Draw the editor again from the model; what is typed is already in the model, so nothing is lost.
     */
    async _render() {
        const {html, js} = await Templates.renderForPromise('local_coursegen/presentation_editor', this._context());
        Templates.replaceNodeContents(this.element, html, js);
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
        event.preventDefault();

        const action = button.dataset.action.replace('local_coursegen/pe/', '');
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
            add: () => {
                if (slides.length < MAX_SLIDES) {
                    slides.splice(at + 1, 0, this._normalised({}));
                    model.current = slides.length === 1 ? 0 : at + 1;
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
            addquestion: () => {
                slides[at].question = this._newQuestion(button.dataset.kind);
            },
            removequestion: () => {
                slides[at].question = null;
            },
            removeimage: () => {
                Object.assign(slides[at], {uploaded_image: false, image_slot: null});
            },
        };
        if (moves[action]) {
            moves[action]();
            return true;
        }
        const asynchronous = {
            apply: () => this._askAi(button.dataset.zone),
            create: () => this._create(),
            generate: () => this._generate(),
            attach: () => this._attach(),
            removefile: () => this.reactive.dispatch('setUpload', {draftitemid: null, filename: ''}),
            uploadimage: () => this._uploadImage(),
        };
        if (asynchronous[action]) {
            await asynchronous[action]();
        }
        return false;
    }

    /**
     * Choose a file, such as a syllabus, the AI bases the presentation on.
     */
    async _attach() {
        const state = this.reactive.state;
        if (state.upload.draftitemid) {
            return;
        }
        await pickFile({
            courseid: state.page.courseid,
            onPicked: (file) => this.reactive.dispatch('setUpload', file),
        });
    }

    /**
     * Choose a picture for the current slide: it goes in as it is, instead of the AI making one.
     */
    async _uploadImage() {
        const slide = this._currentSlide();
        const used = this.model.slides.map((other) => other.image_slot).filter(Boolean);
        let slot = slide.image_slot;
        for (let candidate = 1; !slot && candidate <= MAX_SLIDES; candidate++) {
            slot = used.includes(candidate) ? null : candidate;
        }
        const state = this.reactive.state;
        await pickFile({
            courseid: state.page.courseid,
            acceptedTypes: ['.png', '.jpg', '.jpeg', '.gif'],
            onPicked: async(file) => {
                try {
                    await this._ensureSession();
                    await repository.uploadSlideImage({
                        courseid: state.page.courseid,
                        jobid: String(state.session.jobid),
                        draftitemid: file.draftitemid,
                        slide: slot,
                    });
                    Object.assign(slide, {uploaded_image: true, image_slot: slot, image_prompt: ''});
                    this.errormessage = '';
                } catch (error) {
                    this.errormessage = this._messageOf(error);
                }
                await this._render();
            },
        });
    }

    /**
     * Ask the AI to write all the presentation from the topic and what every slide says it should have.
     */
    async _generate() {
        if (!this.topic.trim()) {
            this.errormessage = this.strings.pe_error_brief;
            await this._render();
            this.element.querySelector("[data-field='topic']")?.focus();
            return;
        }
        if (this._hasContent()) {
            try {
                await Notification.saveCancelPromise(
                    this.strings.pe_regenerate_title, this.strings.pe_regenerate_body, this.strings.pe_regenerate_yes);
            } catch (cancelled) {
                return;
            }
        }

        this.busy = 'all';
        this.errormessage = '';
        await this._render();
        try {
            const started = Boolean(this.reactive.state.session.jobid);
            await this._ensureSession();
            await this._sendFile(started);
            const draft = await repository.draftPresentation(this._job({instructions: this._instructions()}));
            const before = this.model.slides;
            this.model.name = this.model.name || draft.name || '';
            this.model.intro = draft.intro || '';
            this.model.current = 0;
            this.model.slides = (draft.slides || []).map((slide, index) => {
                const written = this._normalised(slide);
                if (before.length === (draft.slides || []).length && before[index].uploaded_image) {
                    Object.assign(written, {uploaded_image: true, image_slot: before[index].image_slot});
                }
                return written;
            });
        } catch (error) {
            this.errormessage = this._messageOf(error);
        }
        this.busy = '';
        await this._render();
    }

    /**
     * The file attached after the job started has to be sent on its own; the one attached before goes with the job.
     *
     * @param {boolean} started Whether the job already existed before this call.
     */
    async _sendFile(started) {
        const state = this.reactive.state;
        const file = state.upload.draftitemid;
        if (started && file && this.sentFile !== file) {
            await repository.uploadFile({courseid: state.page.courseid, jobid: String(state.session.jobid), draftitemid: file});
        }
        this.sentFile = file;
    }

    /**
     * @returns {string} What the AI is told to write: the topic and, slide by slide, what each one has to say.
     */
    _instructions() {
        const slides = this.model.slides;
        const lines = ['Create a Course Presentation H5P.', 'Topic and goal: ' + this.topic.trim()];
        if (this.reactive.state.upload.filename) {
            lines.push('Base the content on the attached document.');
        }
        if (!slides.length) {
            const count = Number(this.count) || 0;
            lines.push(count ? 'Number of slides: exactly ' + count + '.' :
                'Number of slides: the ones the topic needs, at least ' + MIN_SLIDES + '.');
            lines.push('Decide yourself what each slide has: write the slides the topic needs.');
            return lines.join('\n');
        }
        lines.push('Number of slides: exactly ' + slides.length + '.');
        lines.push('The slides, in this order. Follow this structure and write only what each slide asks for:');
        slides.forEach((slide, index) => {
            lines.push((index + 1) + '. ' + this._slideLine(slide));
        });
        return lines.join('\n');
    }

    /**
     * @param {Object} slide
     * @returns {string} One slide as a line of the instruction.
     */
    _slideLine(slide) {
        const said = slide.instr;
        const pieces = [
            'title: ' + (said.title.trim() || (slide.title.trim() ? '"' + slide.title.trim() + '"' : 'write a short one')),
            'text: ' + (said.body.trim() || plain(slide.body_html) || 'explain what the slide needs'),
        ];
        if (slide.uploaded_image) {
            pieces.push('image: the picture the person uploaded for this slide, which is added as it is: do not make one');
        } else if (said.image.trim() || slide.image_prompt.trim()) {
            pieces.push('image: ' + (said.image.trim() || slide.image_prompt.trim()));
        }
        if (slide.question) {
            pieces.push('question (' + QUESTION_NAMES[slide.question.type] + '): ' +
                (said.question.trim() || slide.question.prompt || 'a question that checks the slide'));
        }
        if (said.slide.trim()) {
            pieces.push('about the whole slide: ' + said.slide.trim());
        }
        return pieces.join('; ') + '.';
    }

    /**
     * Ask the AI to write one part of the current slide, or all of it, from the line of that part.
     *
     * @param {string} zone
     */
    async _askAi(zone) {
        const slide = this._currentSlide();
        const instruction = slide.instr[zone].trim();
        if (!instruction) {
            this.element.querySelector(`[data-instr='${zone}']`)?.focus();
            return;
        }

        const index = this.model.current;
        this.busy = zone;
        await this._render();
        try {
            await this._ensureSession();
            const written = await repository.rewriteSlide(this._job({
                slides: this._cleanSlides(),
                index,
                instruction,
                part: PART_OF_ZONE[zone],
            }));
            this._apply(index, zone, this._normalised(written));
            slide.instr[zone] = '';
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
            await this._ensureSession();
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
        const fields = {topic: 'topic', count: 'count'};
        if (fields[target.dataset.field]) {
            this[fields[target.dataset.field]] = target.value;
            return;
        }
        if (target.dataset.field === 'name') {
            this.model.name = target.value;
            return;
        }

        const slide = this._currentSlide();
        if (!slide) {
            return;
        }
        const field = target.dataset.field;
        if (target.dataset.instr) {
            slide.instr[target.dataset.instr] = target.value;
        } else if (field === 'title') {
            slide.title = target.value;
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
     * Update the title of the current slide in the list, without drawing the editor again.
     *
     * @param {string} title
     */
    _refreshStrip(title) {
        const label = this.element.querySelector('.pe-item.active .pe-item-title');
        if (label) {
            label.textContent = title || this.strings.pe_slide_label + ' ' + (this.model.current + 1);
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
     * The choices that are made with a change and not typing: the right option and true or false.
     *
     * @param {Event} event
     */
    _handleChange(event) {
        const target = event.target;
        const slide = this.model ? this._currentSlide() : null;
        if (!slide || !slide.question) {
            return;
        }
        if (target.dataset.question === 'correct') {
            slide.question.options.forEach((option, position) => {
                option.correct = position === Number(target.dataset.option);
            });
        } else if (target.dataset.question === 'truefalse') {
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
