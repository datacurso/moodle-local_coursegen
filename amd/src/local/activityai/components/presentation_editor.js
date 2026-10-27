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
 * Each element of each slide is an instruction for the AI or something fixed the person wrote or uploaded. One
 * button makes the AI write what is left, and the activity is made from what is on the screen.
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
import * as model from 'local_coursegen/local/activityai/presentation_model';
import {buildContext} from 'local_coursegen/local/activityai/presentation_view';

const COMPONENT = 'local_coursegen';
const KIND = 'course_presentation';

/** The part of a slide the AI writes again for each element. */
const PART = {title: 'title', text: 'body', image: 'image', question: 'question'};

/** What the text of a slide may carry when the AI wrote it, and when the person did. */
const SAFE_AI = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'blockquote', 'table', 'thead',
        'tbody', 'tr', 'th', 'td', 'a', 'span', 'div', 'code'],
    ALLOWED_ATTR: ['href'],
};
const SAFE_FIXED = {
    ALLOWED_TAGS: [...SAFE_AI.ALLOWED_TAGS, 'h1', 'h2', 'h5', 'h6', 'section', 'article', 'figure', 'figcaption', 'hr', 'img'],
    ALLOWED_ATTR: ['href', 'style', 'class', 'target', 'rel', 'src', 'alt', 'colspan', 'rowspan'],
};

const STRING_KEYS = [
    'pe_error_slides', 'pe_error_brief', 'pe_error_range', 'pe_error_notitle', 'pe_error_instr', 'pe_error_title',
    'pe_error_text', 'pe_error_html', 'pe_error_video', 'pe_error_image', 'pe_error_question', 'pe_error_notwritten',
    'pe_regenerate_title',
    'pe_regenerate_body', 'pe_regenerate_yes', 'pe_slide_label', 'pe_option', 'pe_save_error',
    ...model.TYPES.map((type) => 'pe_type_' + type),
    ...model.QUESTION_KINDS.map((kind) => 'pe_kind_' + kind),
    ...model.TYPES.map((type) => 'pe_ph_' + type),
];

/**
 * @param {string} html
 * @param {boolean} fixed Whether the person wrote it.
 * @returns {string} The HTML, safe.
 */
const clean = (html, fixed) => {
    const purify = DOMPurify.default || DOMPurify;
    return purify.sanitize(String(html || ''), fixed ? SAFE_FIXED : SAFE_AI);
};

/**
 * @param {HTMLElement} area
 */
const grow = (area) => {
    area.style.height = 'auto';
    area.style.height = area.scrollHeight + 'px';
};

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_presentation_editor';
        this.footer = descriptor.footer || null;
        this.model = null;
        this.ui = {
            busy: '', error: '', errors: {blocks: {}, slide: {}}, topic: '', copyFor: null, filename: '',
            languages: descriptor.languages || [],
        };
        this.sentFile = null;
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
            this.model = {
                mode: 'manual', min: 5, max: 8, name: '', intro: '', current: 0,
                slides: [],
            };
            await this._render();
        } else if (!visible && !session.jobid) {
            this.model = null;
            this.ui.topic = '';
        }
    }

    /**
     * The chat form of the footer is not used here: the language is chosen in the editor and images per element.
     *
     * @param {boolean} hidden
     */
    _syncFooter(hidden) {
        const form = this.footer?.querySelector('form');
        const chat = form?.closest('[data-region="local_coursegen/activity/chat"]') || form;
        if (chat) {
            chat.classList.toggle('d-none', hidden);
        }
    }

    _refreshFile() {
        this.ui.filename = this.reactive.state.upload.filename || '';
        if (this.model) {
            this._render();
        }
    }

    async _render() {
        const context = buildContext(this.model, this.ui, this.strings, clean);
        const {html, js} = await Templates.renderForPromise('local_coursegen/presentation_editor', context);
        Templates.replaceNodeContents(this.element, html, js);
        this.element.querySelectorAll('textarea').forEach(grow);
    }

    /**
     * @returns {Object} The slide being edited.
     */
    _slide() {
        return this.model.slides[this.model.current];
    }

    /**
     * @param {string} id
     * @returns {Object|undefined} An element of the slide being edited.
     */
    _block(id) {
        return this._slide().blocks.find((block) => block.id === id);
    }

    async _handleClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !this.element.contains(button) || !button.dataset.action.startsWith('local_coursegen/pe/')) {
            return;
        }
        event.preventDefault();
        if (!this.model || this.ui.busy) {
            return;
        }
        const action = button.dataset.action.replace('local_coursegen/pe/', '');
        const redraw = this._change(action, button) || await this._run(action, button);
        if (redraw !== false) {
            await this._render();
        }
    }

    /**
     * The actions that only change the slides.
     *
     * @param {string} action
     * @param {HTMLElement} button
     * @returns {boolean} Whether the action was one of these.
     */
    _change(action, button) {
        const handlers = {
            select: () => this._select(Number(button.dataset.index)),
            addslide: () => this._addSlide(button.dataset.layout || 'content'),
            sup: () => this._moveSlide(-1),
            sdown: () => this._moveSlide(1),
            sdup: () => this._duplicateSlide(),
            sdel: () => this._removeSlide(),
            badd: () => this._addBlock(button.dataset.type),
            bdel: () => this._removeBlock(button.dataset.block),
            bup: () => this._moveBlock(button.dataset.block, -1),
            bdown: () => this._moveBlock(button.dataset.block, 1),
            mode: () => this._setMode(button.dataset.block, button.dataset.mode),
            qkind: () => this._setKind(button.dataset.block, button.dataset.kind),
            copyopen: () => {
                this.ui.copyFor = this.ui.copyFor === button.dataset.block ? null : button.dataset.block;
            },
            copydo: () => this._copyBlock(button.dataset.block),
            removeimage: () => Object.assign(this._block(button.dataset.block), {uploaded: false, slot: null, name: ''}),
            removefile: () => this.reactive.dispatch('setUpload', {draftitemid: null, filename: ''}),
        };
        if (!handlers[action]) {
            return false;
        }
        this.ui.error = '';
        this.ui.errors = this._noErrors();
        handlers[action]();
        return true;
    }

    /**
     * The actions that call the AI or open the file picker.
     *
     * @param {string} action
     * @param {HTMLElement} button
     * @returns {Promise<boolean|undefined>} False when the editor does not have to be drawn here.
     */
    async _run(action, button) {
        const handlers = {
            generate: () => this._generate(),
            create: () => this._create(),
            regen: () => this._regenerate(button.dataset.block),
            attach: () => this._attach(),
            uploadimage: () => this._uploadImage(button.dataset.block),
        };
        if (handlers[action]) {
            await handlers[action]();
        }
        return false;
    }

    _select(index) {
        this.model.current = index;
        this.ui.copyFor = null;
    }

    _addSlide(layout) {
        if (this.model.slides.length < model.MAX_SLIDES) {
            const at = this.model.slides.length ? this.model.current + 1 : 0;
            this.model.slides.splice(at, 0, model.newSlide(layout));
            this.model.current = at;
        }
    }

    _moveSlide(step) {
        const slides = this.model.slides;
        const from = this.model.current;
        const to = from + step;
        if (to >= 0 && to < slides.length) {
            [slides[from], slides[to]] = [slides[to], slides[from]];
            this.model.current = to;
        }
    }

    _duplicateSlide() {
        if (this.model.slides.length < model.MAX_SLIDES) {
            this.model.slides.splice(this.model.current + 1, 0, model.cloneSlide(this._slide()));
            this.model.current++;
        }
    }

    _removeSlide() {
        if (this.model.slides.length) {
            this.model.slides.splice(this.model.current, 1);
            this.model.current = Math.max(0, Math.min(this.model.current, this.model.slides.length - 1));
        }
    }

    _addBlock(type) {
        const slide = this._slide();
        if (!model.blockOf(slide, type)) {
            slide.blocks.push(model.newBlock(type));
        }
    }

    _removeBlock(id) {
        const blocks = this._slide().blocks;
        blocks.splice(blocks.findIndex((block) => block.id === id), 1);
    }

    _moveBlock(id, step) {
        const blocks = this._slide().blocks;
        const from = blocks.findIndex((block) => block.id === id);
        const to = from + step;
        if (to >= 0 && to < blocks.length) {
            [blocks[from], blocks[to]] = [blocks[to], blocks[from]];
        }
    }

    /**
     * Change who writes an element, keeping what it already has when that can be kept.
     *
     * @param {string} id
     * @param {string} mode
     */
    _setMode(id, mode) {
        const block = this._block(id);
        const before = block.mode;
        if (before === mode) {
            return;
        }
        block.mode = mode;
        if (block.type === 'text') {
            this._textMode(block, before);
        } else if (block.type === 'title' && mode === model.MODE_AI) {
            block.value = '';
        } else if (block.type === 'question' && mode !== model.MODE_AI && !block.question) {
            block.question = this._newQuestion(block.kind);
        }
    }

    /**
     * @param {Object} block A text element whose mode just changed.
     * @param {string} before The mode it had.
     */
    _textMode(block, before) {
        if (before === model.MODE_AI && block.mode === model.MODE_HTML) {
            block.html = block.value;
        } else if (before === model.MODE_AI) {
            block.fixedtext = model.plain(block.value);
        }
        if (block.mode === model.MODE_AI) {
            block.value = '';
        } else {
            block.value = block.mode === model.MODE_HTML ? block.html : model.paragraphs(block.fixedtext);
        }
    }

    _setKind(id, kind) {
        const block = this._block(id);
        block.kind = kind;
        if (block.mode !== model.MODE_AI) {
            block.question = this._newQuestion(kind);
        }
    }

    /**
     * @param {string} kind
     * @returns {Object} An empty question of a kind.
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

    /**
     * Copy an element to the slides ticked next to it.
     *
     * @param {string} id
     */
    _copyBlock(id) {
        const source = this._block(id);
        const boxes = [...this.element.querySelectorAll('[data-copy]:checked')].map((box) => Number(box.dataset.copy));
        boxes.forEach((index) => {
            const blocks = this.model.slides[index].blocks;
            const at = blocks.findIndex((block) => block.type === source.type);
            const copy = model.cloneBlock(source);
            if (at >= 0) {
                blocks[at] = copy;
            } else {
                blocks.push(copy);
            }
        });
        this.ui.copyFor = null;
    }

    async _attach() {
        const state = this.reactive.state;
        if (!state.upload.draftitemid) {
            await pickFile({courseid: state.page.courseid, onPicked: (file) => this.reactive.dispatch('setUpload', file)});
        }
    }

    /**
     * Choose a picture for an element: it goes in as it is, instead of the AI making one.
     *
     * @param {string} id
     */
    async _uploadImage(id) {
        const block = this._block(id);
        const used = this.model.slides.map((slide) => model.blockOf(slide, 'image')).filter(Boolean).map((other) => other.slot);
        let slot = block.slot;
        for (let candidate = 1; !slot && candidate <= model.MAX_SLIDES; candidate++) {
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
                    Object.assign(block, {uploaded: true, slot, name: file.filename, prompt: '', mode: model.MODE_FIXED});
                    this.ui.error = '';
                } catch (error) {
                    this.ui.error = this._messageOf(error);
                }
                await this._render();
            },
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
        await this.reactive.dispatch('setGenerateImages', this.reactive.state.page.canimages ? 1 : 0);
        await this.reactive.dispatch('beginPresentation', {prompt: this.ui.topic || this.model.name || KIND});
        if (!state.session.jobid) {
            throw new Error(this.strings.pe_save_error);
        }
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
     * @param {Object} extra
     * @returns {Object} The course and the job, with what the call adds.
     */
    _job(extra) {
        const state = this.reactive.state;
        return {courseid: state.page.courseid, jobid: String(state.session.jobid), ...extra};
    }

    _messageOf(error) {
        return String((error && (error.message || error.error)) || this.strings.pe_save_error);
    }

    /**
     * @returns {Object[]} The slides as the service takes them.
     */
    _serviceSlides() {
        return this.model.slides.map((slide) => model.toServiceSlide(slide, clean));
    }

    /**
     * Ask the AI to write one slide, or one part of it.
     *
     * @param {number} index
     * @param {string} instruction
     * @param {string} part The part, or an empty string for the whole slide.
     * @returns {Promise<Object>} The slide as the service gives it.
     */
    _rewrite(index, instruction, part) {
        return repository.rewriteSlide(this._job({slides: this._serviceSlides(), index, instruction, part}));
    }

    /**
     * Write all the presentation from the general instruction and what each element asks for.
     */
    async _generate() {
        if (await this._confirmRewrite()) {
            await this._write();
        }
    }

    /**
     * @returns {Promise<boolean>} Whether to go on: the AI is about to replace what it wrote before.
     */
    async _confirmRewrite() {
        if (this.model.mode !== 'manual' || !model.hasResults(this.model.slides)) {
            return true;
        }
        try {
            await Notification.saveCancelPromise(
                this.strings.pe_regenerate_title, this.strings.pe_regenerate_body, this.strings.pe_regenerate_yes);
        } catch (cancelled) {
            return false;
        }
        return true;
    }

    /**
     * Ask the AI to write everything that is an instruction.
     *
     * @returns {Promise<boolean>} Whether it was written.
     */
    async _write() {
        if (!await this._showErrors(this._validate(false))) {
            return false;
        }

        this.ui.busy = 'all';
        this.ui.error = '';
        await this._render();
        let written = false;
        try {
            const started = Boolean(this.reactive.state.session.jobid);
            await this._ensureSession();
            await this._sendFile(started);
            const instructions = model.compileInstructions({
                topic: this.ui.topic,
                mode: this.model.mode,
                min: this.model.min,
                max: this.model.max,
                filename: this.ui.filename,
                slides: this.model.slides,
            });
            this._takeDraft(await repository.draftPresentation(this._job({instructions})));
            await this._fillMarkers();
            written = true;
        } catch (error) {
            this.ui.error = this._messageOf(error);
        }
        this.ui.busy = '';
        await this._render();
        return written;
    }

    /**
     * @param {Object} draft What the AI service answered.
     */
    _takeDraft(draft) {
        const written = draft.slides || [];
        this.model.name = this.model.name || draft.name || '';
        this.model.intro = draft.intro || '';
        if (this.model.mode === 'auto') {
            this.model.slides = written.map(model.slideFromWritten);
            this.model.mode = 'manual';
            this.model.current = 0;
        } else {
            model.applyDraft(this.model.slides, written);
        }
    }

    /**
     * In the elements with HTML, the AI writes only what the markers ask for and the rest stays as it is.
     */
    async _fillMarkers() {
        const slides = this.model.slides;
        for (let index = 0; index < slides.length; index++) {
            const block = model.blockOf(slides[index], 'text');
            if (block && block.mode === model.MODE_HTML) {
                block.value = await model.fillMarkers(block.html, async(prompt) => {
                    const ask = 'Write only this piece of text, in one short paragraph and with no title: ' + prompt;
                    const written = await this._rewrite(index, ask, 'body');
                    return model.plain(written.body_html);
                });
            }
        }
    }

    /**
     * Write one element again from its instruction.
     *
     * @param {string} id
     */
    async _regenerate(id) {
        const block = this._block(id);
        if (!block.instr.trim()) {
            this.element.querySelector(`[data-block='${id}'][data-f='instr']`)?.focus();
            return;
        }
        this.ui.busy = id;
        this.ui.error = '';
        await this._render();
        try {
            await this._ensureSession();
            const written = await this._rewrite(this.model.current, block.instr.trim(), PART[block.type]);
            model.applyWritten(block, written);
        } catch (error) {
            this.ui.error = this._messageOf(error);
        }
        this.ui.busy = '';
        await this._render();
    }

    /**
     * Write what is still an instruction, build the activity from the slides and go to it.
     */
    async _create() {
        if (this.model.mode !== 'manual' || model.hasPending(this.model.slides)) {
            if (!await this._confirmRewrite() || !await this._write()) {
                return;
            }
        }
        if (!await this._showErrors(this._validate(true))) {
            return;
        }

        this.ui.busy = 'create';
        this.ui.error = '';
        await this._render();
        try {
            await this._ensureSession();
            await repository.buildPresentation(this._job({
                name: (this.model.name.trim() || this.ui.topic.trim().slice(0, 60)),
                intro: this.model.intro,
                slides: this._serviceSlides(),
            }));
            await createActivityFromJob(this.reactive, await loadActivityaiStrings());
        } catch (error) {
            this.ui.error = this._messageOf(error);
            Notification.exception(error);
        }
        this.ui.busy = '';
        await this._render();
    }

    /**
     * @returns {Object} No problems.
     */
    _noErrors() {
        return {blocks: {}, slide: {}};
    }

    /**
     * Find what is wrong in the fields, field by field.
     *
     * @param {boolean} written Whether the AI already had its turn: before it, the instructions are what has to be there.
     * @returns {Object} The problems, by field.
     */
    _validate(written) {
        const errors = this._noErrors();
        const text = this.strings;
        const {slides, mode, min, max} = this.model;
        if (!written && !this.ui.topic.trim()) {
            errors.topic = text.pe_error_brief;
        }
        if (mode === 'auto') {
            if (!written && !(min >= model.MIN_SLIDES && max <= model.MAX_SLIDES && max >= min)) {
                errors.range = text.pe_error_range;
            }
            return errors;
        }
        if (slides.length < model.MIN_SLIDES) {
            errors.slides = text.pe_error_slides;
        }
        slides.forEach((slide, index) => {
            if (!model.blockOf(slide, 'title')) {
                errors.slide[index] = text.pe_error_notitle;
            }
            slide.blocks.forEach((block) => {
                const code = model.blockProblem(block, written);
                if (code) {
                    errors.blocks[block.id] = text['pe_error_' + code];
                }
            });
        });
        return errors;
    }

    /**
     * Mark the fields with a problem, go to the first one and focus it.
     *
     * @param {Object} errors
     * @returns {Promise<boolean>} True when there is nothing wrong.
     */
    async _showErrors(errors) {
        const slides = this.model.slides;
        const found = slides.map((slide, index) => slide.blocks.some((block) => errors.blocks[block.id]) ||
            errors.slide[index] ? index : -1).filter((index) => index >= 0);
        if (!errors.topic && !errors.range && !errors.slides && !found.length) {
            return true;
        }
        this.ui.errors = errors;
        if (found.length && !found.includes(this.model.current)) {
            this.model.current = found[0];
        }
        await this._render();
        const first = this.element.querySelector('[aria-invalid="true"]');
        first?.scrollIntoView({block: 'center'});
        first?.focus({preventScroll: true});
        return false;
    }

    /**
     * Take the mark of a problem off a field as soon as the person works on it.
     *
     * @param {HTMLElement} target
     */
    _clearError(target) {
        const field = target.dataset.field;
        const key = target.dataset.block || {topic: 'topic', min: 'range', max: 'range'}[field];
        if (!key) {
            return;
        }
        const nodes = target.dataset.block ? [...target.closest('.pe-block').querySelectorAll('.is-invalid')] :
            [...this.element.querySelectorAll(field === 'topic' ? '#pe-topic' : '[data-field="min"], [data-field="max"]')];
        nodes.forEach((node) => {
            node.classList.remove('is-invalid');
            node.removeAttribute('aria-invalid');
        });
        this.element.querySelectorAll(`[data-error-for='${key}']`).forEach((node) => node.remove());
        delete this.ui.errors[key];
        delete this.ui.errors.blocks[key];
    }

    /**
     * Keep the slides in step with what is typed.
     *
     * @param {Event} event
     */
    _handleInput(event) {
        const target = event.target;
        if (!this.model) {
            return;
        }
        if (target.tagName === 'TEXTAREA') {
            grow(target);
        }
        if (target.dataset.field === 'lang') {
            this.reactive.dispatch('setLang', String(target.value || '').toLowerCase());
            return;
        }
        this._clearError(target);
        const own = {topic: () => (this.ui.topic = target.value), name: () => (this.model.name = target.value),
            min: () => (this.model.min = Number(target.value) || model.MIN_SLIDES),
            max: () => (this.model.max = Number(target.value) || model.MIN_SLIDES)};
        if (own[target.dataset.field]) {
            own[target.dataset.field]();
        } else if (target.dataset.block && this._block(target.dataset.block)) {
            this._typed(this._block(target.dataset.block), target);
        }
    }

    /**
     * @param {Object} block
     * @param {HTMLElement} target
     */
    _typed(block, target) {
        const field = target.dataset.f;
        const question = block.question;
        if (field === 'fixedtext') {
            block.fixedtext = target.value;
            block.value = model.paragraphs(target.value);
        } else if (field === 'html') {
            block.html = target.value;
            block.value = target.value;
            const count = this.element.querySelector(`[data-markers='${block.id}']`);
            if (count) {
                count.textContent = String(model.markersOf(block.html).length);
            }
        } else if (field === 'instr' || field === 'url' || field === 'value') {
            block[field] = target.value;
        } else if (question && field === 'qprompt') {
            question.prompt = target.value;
        } else if (question && field === 'qtext') {
            question.text = target.value;
        } else if (question && field === 'qoption') {
            question.options[Number(target.dataset.option)].text = target.value;
        }
    }

    /**
     * The choices made with a change and not typing: the right option, true or false, the slides ticked to copy to.
     *
     * @param {Event} event
     */
    async _handleChange(event) {
        const target = event.target;
        if (!this.model) {
            return;
        }
        if (target.dataset.field === 'auto') {
            this.model.mode = target.checked ? 'auto' : 'manual';
            await this._render();
            return;
        }
        if (target.dataset.field === 'addtype' && target.value) {
            this._addBlock(target.value);
            await this._render();
            return;
        }
        const block = target.dataset.block ? this._block(target.dataset.block) : null;
        if (target.dataset.copyall !== undefined) {
            this.element.querySelectorAll('[data-copy]').forEach((box) => {
                box.checked = target.checked;
            });
        } else if (block && block.question && target.dataset.f === 'qcorrect') {
            block.question.options.forEach((option, position) => {
                option.correct = position === Number(target.dataset.option);
            });
        } else if (block && block.question && target.dataset.f === 'qtf') {
            block.question.correct = target.value === 'true';
        }
    }
}
