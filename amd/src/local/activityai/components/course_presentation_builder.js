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
 * The form to lay out a Course Presentation inside the chat, instead of the single field.
 *
 * What the person fills in is turned into one instruction that says, slide by slide, what to write.
 *
 * @module     local_coursegen/local/activityai/components/course_presentation_builder
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_strings as getStrings} from 'core/str';

const COMPONENT = 'local_coursegen';
const KIND = 'course_presentation';
const MIN_SLIDES = 3;
const MAX_SLIDES = 30;

/** The parts each kind of slide has. */
const PARTS = {
    cover: ['title', 'image'],
    content: ['title', 'text'],
    content_image: ['title', 'text', 'image'],
    content_video: ['title', 'text', 'video'],
    question: ['title', 'question'],
    content_question: ['title', 'text', 'question'],
    closing: ['title', 'text'],
};

/** The kinds of question the service writes today. */
const QUESTION_KINDS = ['none', 'multi_choice', 'true_false', 'fill_in_the_blanks'];

const TONES = ['neutral', 'close', 'formal', 'technical'];

/** How each kind of slide is named in the instruction, which is written in English. */
const KIND_NAMES = {
    cover: 'cover', content: 'content', content_image: 'content with an image', content_video: 'content with a video',
    question: 'question', content_question: 'content with a question', closing: 'closing',
};

const QUESTION_NAMES = {
    multi_choice: 'multiple choice', true_false: 'true or false', fill_in_the_blanks: 'fill in the blanks',
};

const TONE_NAMES = {neutral: 'neutral', close: 'close and friendly', formal: 'formal', technical: 'technical'};

/**
 * The slides a new presentation starts with, so the form shows how it is used.
 *
 * @returns {Object[]}
 */
const starterSlides = () => ['cover', 'content', 'content', 'question', 'closing'].map((template) => newSlide(template));

/**
 * An empty slide of a kind.
 *
 * @param {string} template
 * @returns {Object}
 */
const newSlide = (template) => ({
    template,
    title: '',
    text: '',
    imagemode: 'none',
    image: '',
    video: '',
    qtype: PARTS[template].includes('question') ? 'multi_choice' : 'none',
    question: '',
});

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_course_presentation_builder';
        this.footer = descriptor.footer || null;
        this.model = {topic: '', tone: 'neutral', exact: false, exactcount: 6, slides: starterSlides()};
        this.strings = null;
    }

    async stateReady() {
        const keys = [
            ...Object.keys(PARTS).map((name) => 'cpb_tpl_' + name),
            ...TONES.map((name) => 'cpb_tone_' + name),
            'cpb_q_none', 'cpb_q_multi', 'cpb_q_tf', 'cpb_q_fill',
            'cpb_error_topic', 'cpb_error_slides', 'cpb_error_video',
        ];
        const texts = await getStrings(keys.map((key) => ({key, component: COMPONENT})));
        this.strings = {};
        keys.forEach((key, position) => {
            this.strings[key] = texts[position];
        });

        this.addEventListener(this.element, 'input', this._handleInput);
        this.addEventListener(this.element, 'change', this._handleInput);
        this.addEventListener(this.element, 'click', this._handleClick);
        await this._render();
    }

    getWatchers() {
        return [
            {watch: 'session.typestep:updated', handler: this._sync},
            {watch: 'session.h5ptype:updated', handler: this._sync},
            {watch: 'session.jobid:updated', handler: this._sync},
            {watch: 'session.locked:updated', handler: this._sync},
        ];
    }

    /**
     * The form shows while the kind is Course Presentation and nothing was sent yet; the single field of the chat
     * is hidden meanwhile.
     */
    _sync() {
        const session = this.reactive.state.session;
        const visible = session.typestep === 'prompt' && session.activitytype === 'h5pactivity' &&
            session.h5ptype === KIND && !session.jobid && !session.locked;
        this.element.querySelector("[data-region='local_coursegen/activity/builder']")?.classList.toggle('d-none', !visible);
        const field = this.footer?.querySelector('.ai-prompt-container');
        const forThisKind = session.typestep === 'prompt' && session.activitytype === 'h5pactivity' &&
            session.h5ptype === KIND && !session.jobid;
        if (field) {
            // Inline and important: the field is a flex box, whose class would win over d-none and the hidden attribute.
            if (forThisKind) {
                field.style.setProperty('display', 'none', 'important');
            } else {
                field.style.removeProperty('display');
            }
        }
        // The images and language options of the footer stay, because they apply to the presentation too.
    }

    async _render() {
        const model = this.model;
        const slides = model.slides.map((slide, index) => this._slideContext(slide, index, model.slides.length));
        const context = {
            topic: model.topic,
            tones: TONES.map((value) => ({
                value, label: this.strings['cpb_tone_' + value], selected: value === model.tone,
            })),
            exact: model.exact,
            exactcount: model.exactcount,
            slides,
        };
        const {html, js} = await Templates.renderForPromise('local_coursegen/course_presentation_builder', context);
        Templates.replaceNodeContents(this.element, html, js);
        this._sync();
    }

    /**
     * What the template needs to draw one slide.
     *
     * @param {Object} slide
     * @param {number} index
     * @param {number} total
     * @returns {Object}
     */
    _slideContext(slide, index, total) {
        const parts = PARTS[slide.template];
        const labels = {
            none: this.strings.cpb_q_none, multi_choice: this.strings.cpb_q_multi,
            true_false: this.strings.cpb_q_tf, fill_in_the_blanks: this.strings.cpb_q_fill,
        };
        return {
            index,
            number: index + 1,
            templates: Object.keys(PARTS).map((value) => ({
                value, label: this.strings['cpb_tpl_' + value], selected: value === slide.template,
            })),
            hastitle: parts.includes('title'),
            hastext: parts.includes('text'),
            hasimage: parts.includes('image'),
            hasvideo: parts.includes('video'),
            hasquestion: parts.includes('question'),
            title: slide.title,
            text: slide.text,
            image: slide.image,
            video: slide.video,
            question: slide.question,
            imagegenerate: slide.imagemode === 'generate',
            qtypes: QUESTION_KINDS.map((value) => ({value, label: labels[value], selected: value === slide.qtype})),
            qchosen: slide.qtype !== 'none',
            first: index === 0,
            last: index === total - 1,
        };
    }

    /**
     * Keep the model in step with what is typed; the fields that change what is drawn redraw the form.
     *
     * @param {Event} event
     */
    async _handleInput(event) {
        const target = event.target;
        const slideNode = target.closest("[data-region='local_coursegen/builder/slide']");
        if (slideNode) {
            const slide = this.model.slides[Number(slideNode.dataset.index)];
            const field = target.dataset.slideField;
            if (!slide || !field) {
                return;
            }
            slide[field] = target.value;
            if (event.type === 'change' && ['template', 'imagemode', 'qtype'].includes(field)) {
                if (field === 'template') {
                    const wasQuestion = PARTS[slide.template]?.includes('question');
                    slide.qtype = PARTS[target.value].includes('question') && slide.qtype === 'none' && !wasQuestion
                        ? 'multi_choice' : slide.qtype;
                    if (!PARTS[target.value].includes('question')) {
                        slide.qtype = 'none';
                    }
                }
                await this._render();
            }
            return;
        }

        const field = target.dataset.field;
        if (!field) {
            return;
        }
        if (field === 'count') {
            this.model.exact = target.value === 'exact';
            await this._render();
        } else if (field === 'exactcount') {
            this.model.exactcount = Number(target.value) || MIN_SLIDES;
        } else {
            this.model[field] = target.value;
        }
    }

    async _handleClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !this.element.contains(button)) {
            return;
        }

        const action = button.dataset.action;
        if (!action.startsWith('local_coursegen/builder/')) {
            return;
        }
        event.preventDefault();

        const slides = this.model.slides;
        const index = Number(button.closest("[data-region='local_coursegen/builder/slide']")?.dataset.index);
        switch (action.replace('local_coursegen/builder/', '')) {
            case 'add':
                if (slides.length < MAX_SLIDES) {
                    slides.push(newSlide('content'));
                }
                break;
            case 'up':
                if (index > 0) {
                    [slides[index - 1], slides[index]] = [slides[index], slides[index - 1]];
                }
                break;
            case 'down':
                if (index < slides.length - 1) {
                    [slides[index + 1], slides[index]] = [slides[index], slides[index + 1]];
                }
                break;
            case 'duplicate':
                if (slides.length < MAX_SLIDES) {
                    slides.splice(index + 1, 0, {...slides[index]});
                }
                break;
            case 'remove':
                slides.splice(index, 1);
                break;
            case 'submit':
                await this._submit();
                return;
            default:
                return;
        }
        await this._render();
    }

    /**
     * Check the form and send the instruction it makes.
     */
    async _submit() {
        const error = this._validate();
        const box = this.element.querySelector("[data-region='local_coursegen/activity/builder_error']");
        box?.classList.toggle('d-none', error === '');
        if (box) {
            box.textContent = error;
        }
        if (error) {
            box?.scrollIntoView({block: 'center'});
            return;
        }

        try {
            await this.reactive.dispatch('submitPrompt', {prompt: this._instruction()});
        } catch (exception) {
            Notification.exception(exception);
        }
    }

    /**
     * @returns {string} What is wrong with the form, or an empty string.
     */
    _validate() {
        if (!this.model.topic.trim()) {
            return this.strings.cpb_error_topic;
        }
        const total = this.model.exact ? this.model.exactcount : this.model.slides.length;
        if (this.model.slides.length < MIN_SLIDES || total < MIN_SLIDES) {
            return this.strings.cpb_error_slides;
        }
        const badVideo = this.model.slides.some((slide) => {
            const video = slide.video.trim();
            return PARTS[slide.template].includes('video') && video !== '' && !/^https?:\/\//i.test(video);
        });
        return badVideo ? this.strings.cpb_error_video : '';
    }

    /**
     * The instruction for the AI that the form makes: what the presentation is about and, slide by slide, what to write.
     *
     * @returns {string}
     */
    _instruction() {
        const model = this.model;
        const lines = [
            'Create a Course Presentation H5P.',
            'Topic and objective: ' + model.topic.trim(),
            'Tone: ' + TONE_NAMES[model.tone] + '.',
            model.exact
                ? 'Number of slides: exactly ' + model.exactcount + '.'
                : 'Number of slides: the ones the content needs, at least ' + MIN_SLIDES + '.',
            'The slides, in this order. Follow this structure and write only what each slide asks for:',
        ];
        model.slides.forEach((slide, index) => {
            lines.push((index + 1) + '. ' + this._slideLine(slide));
        });
        return lines.join('\n');
    }

    /**
     * One slide as a line of the instruction.
     *
     * @param {Object} slide
     * @returns {string}
     */
    _slideLine(slide) {
        const parts = PARTS[slide.template];
        const pieces = [];
        if (parts.includes('title')) {
            pieces.push(slide.title.trim() ? 'title: "' + slide.title.trim() + '"' : 'title: write a short one');
        }
        if (parts.includes('text')) {
            pieces.push(slide.text.trim() ? 'text: ' + slide.text.trim() : 'text: explain what the slide needs');
        }
        if (parts.includes('image') && slide.imagemode === 'generate') {
            pieces.push('image: ' + (slide.image.trim() || 'an image that illustrates the slide'));
        }
        if (parts.includes('video') && slide.video.trim()) {
            pieces.push('video: ' + slide.video.trim());
        }
        if (parts.includes('question') && slide.qtype !== 'none') {
            pieces.push('question (' + QUESTION_NAMES[slide.qtype] + '): ' +
                (slide.question.trim() || 'a question that checks the slide'));
        }
        return 'Slide of kind ' + KIND_NAMES[slide.template] + ' - ' + pieces.join('; ') + '.';
    }
}
