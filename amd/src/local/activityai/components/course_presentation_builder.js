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
import {pickFile} from 'local_coursegen/local/activityai/filepicker';
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

/** The kinds of slide that are, by their name, a slide with an image: the image is not optional there. */
const IMAGE_REQUIRED = ['content_image'];

/** The extensions of the pictures the service accepts for a slide. */
const PICTURE_TYPES = ['.png', '.jpg', '.jpeg', '.gif'];

/** The kinds of question the service writes today. */
const QUESTION_KINDS = ['none', 'multi_choice', 'true_false', 'fill_in_the_blanks'];

/** How each kind of slide is named in the instruction, which is written in English. */
const KIND_NAMES = {
    cover: 'cover', content: 'content', content_image: 'content with an image', content_video: 'content with a video',
    question: 'question', content_question: 'content with a question', closing: 'closing',
};

const QUESTION_NAMES = {
    multi_choice: 'multiple choice', true_false: 'true or false', fill_in_the_blanks: 'fill in the blanks',
};

/**
 * How the image of a new slide is made: a slide that is, by its name, a slide with an image asks for it, by
 * generating it when the person may and by uploading it when not.
 *
 * @param {string} template
 * @param {boolean} canImages Whether the person may generate images.
 * @returns {string}
 */
const defaultImageMode = (template, canImages) => {
    if (!IMAGE_REQUIRED.includes(template)) {
        return 'none';
    }
    return canImages ? 'generate' : 'upload';
};

/**
 * An empty slide of a kind.
 *
 * @param {string} template
 * @param {boolean} canImages Whether the person may generate images.
 * @returns {Object}
 */
const newSlide = (template, canImages = true) => ({
    template,
    title: '',
    text: '',
    imagemode: defaultImageMode(template, canImages),
    image: '',
    imagedraft: 0,
    imagename: '',
    video: '',
    qtype: PARTS[template].includes('question') ? 'multi_choice' : 'none',
    question: '',
});

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_course_presentation_builder';
        this.footer = descriptor.footer || null;
        this.model = {topic: '', mode: 'manual', limit: 'none', min: MIN_SLIDES, max: 8, slides: []};
        this.strings = null;
        // What is wrong with the form, by field, once the person tried to create the presentation.
        this.errors = {};
    }

    async stateReady() {
        const keys = [
            ...Object.keys(PARTS).map((name) => 'cpb_tpl_' + name),
            'cpb_q_none', 'cpb_q_multi', 'cpb_q_tf', 'cpb_q_fill',
            'cpb_error_topic', 'cpb_error_slides', 'cpb_error_video', 'cpb_error_limits', 'cpb_error_image',
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
            {watch: 'upload:updated', handler: this._drawFile},
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
        // Whether images are made is decided slide by slide in the form, so the general choice of the footer is hidden.
        this.footer?.querySelectorAll("input[name='generate_images']").forEach((radio) => {
            const label = radio.closest('label');
            if (!label) {
                return;
            }
            if (forThisKind) {
                label.style.setProperty('display', 'none', 'important');
            } else {
                label.style.removeProperty('display');
            }
        });
        if (forThisKind) {
            this._syncImages();
        }
        // The file shown in the footer is shown by the form instead, so it is not shown twice.
        const shown = this.footer?.querySelector("[data-region='local_coursegen/activity/selectedfile']");
        if (shown) {
            shown.hidden = forThisKind;
        }
        // The images and language options of the footer stay, because they apply to the presentation too.
    }

    async _render() {
        const model = this.model;
        const slides = model.slides.map((slide, index) => this._slideContext(slide, index, model.slides.length));
        const context = {
            topic: model.topic,
            topicerror: this.errors.topic || '',
            limiterror: this.errors.limits || '',
            slideserror: this.errors.slides || '',
            manual: model.mode === 'manual',
            count: model.slides.length,
            limitnone: model.limit === 'none',
            limitmax: model.limit === 'max',
            limitrange: model.limit === 'range',
            min: model.min,
            max: model.max,
            slides,
        };
        const {html, js} = await Templates.renderForPromise('local_coursegen/course_presentation_builder', context);
        Templates.replaceNodeContents(this.element, html, js);
        this._sync();
        this._syncImages();
        this._drawFile();
    }

    /**
     * Images are made when at least one slide asks for one; the general option of the chat follows the slides.
     */
    _syncImages() {
        const wanted = this.model.slides.some((slide) => PARTS[slide.template].includes('image') && slide.imagemode === 'generate');
        const value = wanted && this.reactive.state.page.canimages ? 1 : 0;
        if (this.reactive.state.session.generateimages !== value) {
            this.reactive.dispatch('setGenerateImages', value);
        }
    }

    /**
     * The file attached, if any, with the way to remove it. The upload itself is the one of the chat: the button of
     * the footer opens the file picker and the file travels with the instruction.
     */
    _drawFile() {
        const filename = String(this.reactive.state.upload.filename || '');
        const box = this.element.querySelector("[data-region='local_coursegen/activity/builder_file']");
        const name = this.element.querySelector("[data-region='local_coursegen/activity/builder_file_name']");
        box?.classList.toggle('d-none', filename === '');
        box?.classList.toggle('d-flex', filename !== '');
        if (name) {
            name.textContent = filename;
        }
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
            imageoptional: !IMAGE_REQUIRED.includes(slide.template),
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
            imagenone: slide.imagemode === 'none',
            imagegenerate: slide.imagemode === 'generate',
            imageupload: slide.imagemode === 'upload',
            imagename: slide.imagename,
            canimages: Boolean(this.reactive.state.page.canimages),
            videoerror: this.errors['slide-' + index + '-video'] || '',
            imageerror: this.errors['slide-' + index + '-image'] || '',
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
            if (field === 'video') {
                this._clearError('slide-' + slideNode.dataset.index + '-video', target);
            }
            this._syncImages();
            if (event.type === 'change' && ['template', 'imagemode', 'qtype'].includes(field)) {
                if (field === 'template') {
                    if (IMAGE_REQUIRED.includes(target.value) && slide.imagemode === 'none') {
                        slide.imagemode = defaultImageMode(target.value, Boolean(this.reactive.state.page.canimages));
                    }
                    const wasQuestion = PARTS[slide.template]?.includes('question');
                    slide.qtype = PARTS[target.value].includes('question') && slide.qtype === 'none' && !wasQuestion
                        ? 'multi_choice' : slide.qtype;
                    if (!PARTS[target.value].includes('question')) {
                        slide.qtype = 'none';
                    }
                }
                this.errors = {};
                await this._render();
            }
            return;
        }

        const field = target.dataset.field;
        if (!field) {
            return;
        }
        if (field === 'mode' || field === 'limit') {
            this.model[field] = target.value;
            this.errors = {};
            await this._render();
        } else if (field === 'min' || field === 'max') {
            this.model[field] = Number(target.value) || MIN_SLIDES;
            this._clearError('limits', this.element.querySelector('#cpb-min'), this.element.querySelector('#cpb-max'));
        } else {
            this.model[field] = target.value;
            if (field === 'topic') {
                this._clearError('topic', target);
            }
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
        if (['add', 'up', 'down', 'duplicate', 'remove', 'removeimage'].includes(action.replace('local_coursegen/builder/', ''))) {
            // Slides change places or number, so the errors of the slides no longer point at the same ones.
            this.errors = {};
        }
        switch (action.replace('local_coursegen/builder/', '')) {
            case 'add':
                if (slides.length < MAX_SLIDES) {
                    slides.push(newSlide('content', Boolean(this.reactive.state.page.canimages)));
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
            case 'pickimage':
                await this._pickImage(slides[index]);
                return;
            case 'removeimage':
                slides[index].imagedraft = 0;
                slides[index].imagename = '';
                break;
            case 'attach':
                this.footer?.querySelector("[data-region='local_coursegen/activity/upload']")?.click();
                return;
            case 'detach':
                this.reactive.dispatch('setUpload', {draftitemid: null, filename: ''});
                return;
            case 'submit':
                await this._submit();
                return;
            default:
                return;
        }
        await this._render();
    }

    /**
     * What is wrong with the limits of the number of slides the AI decides, or an empty string.
     *
     * @returns {string}
     */
    _limitsError() {
        const {limit, min, max} = this.model;
        if (limit === 'none') {
            return '';
        }
        const lowest = limit === 'range' ? min : MIN_SLIDES;
        const valid = max >= MIN_SLIDES && max <= MAX_SLIDES && min >= MIN_SLIDES && lowest <= max;
        return valid ? '' : this.strings.cpb_error_limits;
    }

    /**
     * The person fixed a field: its error goes away, without drawing the form again (that would lose the focus).
     *
     * @param {string} key The error.
     * @param {...HTMLElement} fields The fields that showed it.
     */
    _clearError(key, ...fields) {
        delete this.errors[key];
        fields.forEach((field) => field?.classList.remove('is-invalid'));
        if (key === 'limits') {
            this.element.querySelectorAll('.text-danger[role="alert"]').forEach((message) => message.remove());
        }
    }

    /**
     * Choose the picture of a slide with the file picker; the slide shows its name once chosen.
     *
     * @param {Object} slide
     */
    async _pickImage(slide) {
        try {
            await pickFile({
                courseid: this.reactive.state.page.courseid,
                acceptedTypes: PICTURE_TYPES,
                onPicked: async(file) => {
                    slide.imagedraft = file.draftitemid;
                    slide.imagename = file.filename;
                    this.errors = {};
                    await this._render();
                },
            });
        } catch (exception) {
            Notification.exception(exception);
        }
    }

    /**
     * The pictures chosen for the slides, with the number of the slide each goes to.
     *
     * @returns {Array<{slide: number, draftitemid: number}>}
     */
    _slideImages() {
        if (this.model.mode !== 'manual') {
            return [];
        }
        return this.model.slides
            .map((slide, index) => ({slide: index + 1, draftitemid: slide.imagedraft, uses: this._usesPicture(slide)}))
            .filter((image) => image.uses && image.draftitemid)
            .map(({slide, draftitemid}) => ({slide, draftitemid}));
    }

    /**
     * @param {Object} slide
     * @returns {boolean} Whether the slide has a picture the person uploaded.
     */
    _usesPicture(slide) {
        return PARTS[slide.template].includes('image') && slide.imagemode === 'upload';
    }

    /**
     * Check the form: if something is wrong the fields are marked, the first one is brought into view and focused,
     * and nothing is sent; otherwise the instruction is sent.
     */
    async _submit() {
        this.errors = this._validate();
        if (Object.keys(this.errors).length) {
            await this._render();
            this._focusFirstError();
            return;
        }

        this._syncImages();
        try {
            await this.reactive.dispatch('submitPrompt', {prompt: this._instruction(), slideImages: this._slideImages()});
        } catch (exception) {
            Notification.exception(exception);
        }
    }

    /**
     * Bring the first field with an error into view and focus it.
     */
    _focusFirstError() {
        const first = this.element.querySelector('.is-invalid, [data-invalid="true"]');
        first?.scrollIntoView({block: 'center'});
        first?.focus();
    }

    /**
     * What is wrong with the form, by field.
     *
     * @returns {Object} Messages by field: topic, limits, slides, and for each slide its video and image.
     */
    _validate() {
        const errors = {};
        if (!this.model.topic.trim()) {
            errors.topic = this.strings.cpb_error_topic;
        }
        if (this.model.mode === 'auto') {
            const limits = this._limitsError();
            if (limits) {
                errors.limits = limits;
            }
            return errors;
        }
        if (this.model.slides.length < MIN_SLIDES) {
            errors.slides = this.strings.cpb_error_slides;
        }
        this.model.slides.forEach((slide, index) => {
            const video = slide.video.trim();
            if (PARTS[slide.template].includes('video') && video !== '' && !/^https?:\/\//i.test(video)) {
                errors['slide-' + index + '-video'] = this.strings.cpb_error_video;
            }
            if (this._usesPicture(slide) && !slide.imagedraft) {
                errors['slide-' + index + '-image'] = this.strings.cpb_error_image;
            }
        });
        return errors;
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
        ];
        if (this.reactive.state.upload.filename) {
            lines.push('Base the content on the attached document.');
        }
        if (model.mode === 'auto') {
            lines.push('Number of slides: ' + this._limitText() + '.');
            lines.push('Decide yourself what each slide has: write the slides the topic needs.');
            return lines.join('\n');
        }

        lines.push('Number of slides: exactly ' + model.slides.length + '.');
        lines.push('The slides, in this order. Follow this structure and write only what each slide asks for:');
        model.slides.forEach((slide, index) => {
            lines.push((index + 1) + '. ' + this._slideLine(slide));
        });
        return lines.join('\n');
    }

    /**
     * The limit on the number of slides, in words.
     *
     * @returns {string}
     */
    _limitText() {
        const {limit, min, max} = this.model;
        if (limit === 'max') {
            return 'at most ' + max + ' (and at least ' + MIN_SLIDES + ')';
        }
        if (limit === 'range') {
            return 'between ' + min + ' and ' + max;
        }
        return 'the ones the topic needs, at least ' + MIN_SLIDES;
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
        if (this._usesPicture(slide)) {
            pieces.push('image: the picture the person uploaded for this slide, which is added as it is: do not make one');
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
