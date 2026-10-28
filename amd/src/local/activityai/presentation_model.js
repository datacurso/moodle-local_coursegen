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
 * The slides of the Course Presentation editor, without anything to do with the page.
 *
 * Every element of a slide is either an instruction for the AI, which the AI writes when the presentation is
 * generated, or something fixed that the person wrote or uploaded and stays as it is.
 *
 * @module     local_coursegen/local/activityai/presentation_model
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const MODE_AI = 'ai';
export const MODE_FIXED = 'fixed';
export const MODE_HTML = 'html';

export const MIN_SLIDES = 1;
export const MAX_SLIDES = 30;

/** The elements a slide can have, one of each. */
export const TYPES = ['title', 'text', 'image', 'video', 'question'];

/** The questions the AI can write today. */
export const QUESTION_KINDS = ['multi_choice', 'true_false', 'fill_in_the_blanks'];

const MARKER = /\[\[coursegen:aiprompt:\s*([\s\S]*?)\]\]/g;

let counter = 0;

/**
 * @param {string} type One of TYPES.
 * @returns {Object} A new element, empty.
 */
export const newBlock = (type) => ({
    id: 'b' + (++counter),
    type,
    mode: type === 'video' ? MODE_FIXED : MODE_AI,
    instr: '',
    value: '',
    fixedtext: '',
    html: '',
    prompt: '',
    uploaded: false,
    slot: null,
    fileKey: '',
    name: '',
    url: '',
    kind: QUESTION_KINDS[0],
    question: null,
});

/** The elements each layout starts with. */
const LAYOUTS = {
    cover: ['title', 'text', 'image'],
    content: ['title', 'text'],
    split: ['title', 'text', 'image'],
    question: ['title', 'question'],
    blank: ['title'],
};

/**
 * @param {string} layout
 * @returns {Object} A new slide with the elements of the layout.
 */
export const newSlide = (layout) => ({blocks: (LAYOUTS[layout] || LAYOUTS.blank).map(newBlock)});

/**
 * @param {Object} slide
 * @returns {Object} A copy of the slide, with its own ids.
 */
export const cloneSlide = (slide) => ({blocks: slide.blocks.map((block) => cloneBlock(block))});

/**
 * @param {Object} block
 * @returns {Object} A copy of the element, with its own id.
 */
export const cloneBlock = (block) => ({...JSON.parse(JSON.stringify(block)), id: 'b' + (++counter)});

/**
 * @param {Object} slide
 * @param {string} type
 * @returns {Object|undefined} The element of that type.
 */
export const blockOf = (slide, type) => slide.blocks.find((block) => block.type === type);

/**
 * @param {string} text
 * @returns {string} Plain text as paragraphs of HTML.
 */
export const paragraphs = (text) => String(text || '').split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean)
    .map((part) => '<p>' + part.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</p>').join('');

/**
 * @param {string} html
 * @returns {string} The text of some HTML, without its tags.
 */
export const plain = (html) => String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * @param {string} html
 * @returns {string[]} The instructions of the markers an HTML has.
 */
export const markersOf = (html) => [...String(html || '').matchAll(MARKER)].map((found) => found[1].trim());

/**
 * @param {string} html
 * @param {Function} write Gets the instruction of a marker and gives back the text that replaces it.
 * @returns {Promise<string>} The HTML with every marker replaced; the rest of it stays as it is.
 */
export const fillMarkers = async(html, write) => {
    let result = String(html || '');
    for (const prompt of markersOf(result)) {
        const text = String(await write(prompt)).replace(/&/g, '&amp;').replace(/</g, '&lt;');
        result = result.replace(MARKER, (full, inside) => inside.trim() === prompt ? text : full);
    }
    return result;
};

/**
 * @param {Object} block
 * @returns {boolean} Whether the element has what the slide needs from it.
 */
export const isReady = (block) => {
    if (block.type === 'title') {
        return Boolean(block.value.trim());
    }
    if (block.type === 'text') {
        return Boolean(plain(block.value)) && !markersOf(block.value).length;
    }
    if (block.type === 'image') {
        return block.uploaded || Boolean(block.prompt.trim()) || (block.mode === MODE_AI && !block.instr.trim());
    }
    if (block.type === 'question') {
        return Boolean(block.question && block.question.prompt);
    }
    return true;
};

/**
 * @param {Object[]} slides
 * @returns {boolean} Whether an element still waits for the AI to write it.
 */
export const hasPending = (slides) => slides.some((slide) => slide.blocks.some((block) => !isReady(block)));

/**
 * @param {Object[]} slides
 * @returns {boolean} Whether the AI wrote anything in the slides.
 */
export const hasResults = (slides) => slides.some((slide) => slide.blocks.some((block) =>
    block.mode === MODE_AI && (block.value || block.prompt || block.question)));

/**
 * @param {Object} slide
 * @param {Function} clean Makes the text of a slide safe.
 * @returns {Object} The slide as the service takes it.
 */
export const toServiceSlide = (slide, clean) => {
    const get = (type) => blockOf(slide, type);
    const image = get('image');
    const text = get('text');
    return {
        title: get('title') ? get('title').value : '',
        body_html: text ? clean(text.value, text.mode !== MODE_AI) : '',
        image_prompt: image && !image.uploaded ? image.prompt : '',
        video_url: get('video') ? get('video').url : '',
        question: get('question') ? get('question').question : null,
        uploaded_image: Boolean(image && image.uploaded),
        image_slot: image && image.uploaded ? image.slot : null,
    };
};

/**
 * Put what the AI wrote in an element, unless the person fixed it.
 *
 * @param {Object} block
 * @param {Object} written A slide as the service gives it.
 */
export const applyWritten = (block, written) => {
    if (block.mode !== MODE_AI) {
        return;
    }
    if (block.type === 'title') {
        block.value = written.title || '';
    } else if (block.type === 'text') {
        block.value = written.body_html || '';
    } else if (block.type === 'image' && !block.uploaded) {
        block.prompt = written.image_prompt || '';
    } else if (block.type === 'question') {
        block.question = written.question || null;
        block.kind = block.question ? block.question.type : block.kind;
    }
};

/**
 * @param {Object} written A slide as the service gives it.
 * @returns {Object} A slide whose elements are all the AI's.
 */
export const slideFromWritten = (written) => {
    const slide = {blocks: []};
    const add = (type, set) => {
        const block = newBlock(type);
        set(block);
        slide.blocks.push(block);
    };
    add('title', (block) => {
        block.value = written.title || '';
    });
    add('text', (block) => {
        block.value = written.body_html || '';
    });
    if (written.image_prompt) {
        add('image', (block) => {
            block.prompt = written.image_prompt;
        });
    }
    // The AI sometimes answers "null" or another word for no video: only a YouTube address makes an element.
    if (typeof written.video_url === 'string' && YOUTUBE.test(written.video_url.trim())) {
        add('video', (block) => {
            block.url = written.video_url.trim();
        });
    }
    if (written.question) {
        add('question', (block) => {
            block.question = written.question;
            block.kind = written.question.type;
        });
    }
    return slide;
};

/** The name of each question, as the AI is told. */
const QUESTION_NAMES = {multi_choice: 'multiple choice', true_false: 'true or false', fill_in_the_blanks: 'fill in the blanks'};

/**
 * @param {Object} block
 * @returns {string} The part of the instruction for one element, or an empty string if it needs none.
 */
const blockLine = (block) => {
    const said = block.instr.trim();
    const fixed = block.mode !== MODE_AI;
    if (block.type === 'title') {
        return 'title: ' + (fixed ? '"' + block.value.trim() + '" (fixed)' : said || 'write a short one');
    }
    if (block.type === 'text') {
        return 'text: ' + (fixed ? 'write one short sentence, it will be replaced' : said || 'explain what the slide needs');
    }
    if (block.type === 'image') {
        if (block.uploaded) {
            return 'image: the picture the person uploaded, which is added as it is: do not make one';
        }
        return said ? 'image: ' + said : '';
    }
    if (block.type === 'video') {
        return block.url.trim() ? 'video: ' + block.url.trim() : '';
    }
    const asked = fixed ? 'any, it will be replaced' : said || 'a question that checks the slide';
    return 'question (' + QUESTION_NAMES[block.kind] + '): ' + asked;
};

/**
 * @param {Object} options
 * @param {string} options.topic
 * @param {string} options.mode 'manual' or 'auto'.
 * @param {number} options.min
 * @param {number} options.max
 * @param {string} options.filename The attached file, if any.
 * @param {Object[]} options.slides
 * @returns {string} What the AI is told: the topic and, slide by slide, what each element asks for.
 */
export const compileInstructions = ({topic, mode, min, max, filename, slides}) => {
    const lines = ['Create a Course Presentation H5P.', 'Topic and goal: ' + topic.trim()];
    if (filename) {
        lines.push('Base the content on the attached document.');
    }
    if (mode === 'auto') {
        lines.push('Number of slides: between ' + min + ' and ' + max + '.');
        lines.push('Decide yourself what each slide has: write the slides the topic needs.');
        return lines.join('\n');
    }
    lines.push('Number of slides: exactly ' + slides.length + '.');
    lines.push('The slides, in this order. Follow this structure and write only what each slide asks for:');
    slides.forEach((slide, index) => {
        const parts = slide.blocks.map(blockLine).filter(Boolean).join('; ');
        lines.push((index + 1) + '. ' + (parts || 'a slide the topic needs') + '.');
    });
    return lines.join('\n');
};

/**
 * Put the slides the AI wrote into the slides the person defined.
 *
 * @param {Object[]} slides The slides of the person; they are changed.
 * @param {Object[]} written The slides as the service gives them.
 * @returns {boolean} Whether the AI wrote as many slides as were defined.
 */
export const applyDraft = (slides, written) => {
    slides.forEach((slide, index) => {
        if (written[index]) {
            slide.blocks.forEach((block) => applyWritten(block, written[index]));
        }
    });
    return slides.length === written.length;
};

const YOUTUBE = /^https?:\/\/(www\.|m\.)?(youtube\.com\/(watch\?(.*&)?v=|embed\/|shorts\/)|youtu\.be\/)[\w-]{6,}/i;

/**
 * Say what is wrong with an element, so the field that has the problem can be marked.
 *
 * @param {Object} block
 * @param {boolean} written Whether the AI already had its turn to write: an instruction left unwritten is then the problem.
 * @returns {string} A code for the problem, or an empty string.
 */
export const blockProblem = (block, written) => {
    if (block.mode === MODE_AI) {
        if (written) {
            return isReady(block) ? '' : 'notwritten';
        }
        const answered = block.value || block.prompt || block.question;
        return block.instr.trim() || answered ? '' : 'instr';
    }
    const empty = {
        title: !block.value.trim(),
        text: block.mode === MODE_HTML ? !block.html.trim() : !block.fixedtext.trim(),
        image: !block.uploaded,
        question: !(block.question && block.question.prompt.trim()),
    };
    if (block.type === 'video') {
        return YOUTUBE.test(block.url.trim()) ? '' : 'video';
    }
    if (block.type === 'text' && empty.text) {
        return block.mode === MODE_HTML ? 'html' : 'text';
    }
    return empty[block.type] ? block.type : '';
};

/**
 * @param {Object} slide
 * @returns {boolean} Whether the person has put something in the slide: deleting it would lose work.
 */
export const hasContent = (slide) => slide.blocks.some((block) => {
    const question = block.question || {};
    const asked = question.prompt || question.text || (question.options || []).some((option) => option.text);
    const typed = [block.instr, block.value, block.fixedtext, block.html, block.url].some((value) => value.trim());
    return Boolean(block.uploaded || typed || asked);
});

/** The version of the draft the editor saves. Drafts of another version are not used. */
export const DRAFT_VERSION = 1;

/**
 * @param {{topic: string, lang: string, reference: Object|null, model: Object}} state What the editor holds.
 * @returns {string|null} What is saved while the person edits, or null while there is nothing to save.
 */
export const toDraft = ({topic, lang, reference, model}) => {
    if (!topic.trim() && !model.slides.length && !reference) {
        return null;
    }
    const {mode, min, max, name, intro, current, slides} = model;
    return JSON.stringify({v: DRAFT_VERSION, topic, lang, reference, model: {mode, min, max, name, intro, current, slides}});
};

/** What the key of a file kept by the draft looks like: the server accepts only this. */
const FILE_KEY = /^[A-Za-z0-9_-]{8,64}\.[A-Za-z0-9]{2,5}$/;

/**
 * @param {string} extension The extension of the file, with or without the dot.
 * @returns {string} A key for a file the draft keeps.
 */
export const newFileKey = (extension) => {
    const random = Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) => byte.toString(16).padStart(2, '0')).join('');
    const ext = String(extension).replace(/^\./, '').toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 5) || 'bin';
    return random + '.' + (ext.length < 2 ? 'bin' : ext);
};

const text = (value, limit = 20000) => (typeof value === 'string' ? value.slice(0, limit) : '');

const question = (raw) => {
    if (!raw || typeof raw !== 'object') {
        return null;
    }
    return {
        type: QUESTION_KINDS.includes(raw.type) ? raw.type : QUESTION_KINDS[0],
        prompt: text(raw.prompt, 2000),
        text: text(raw.text, 4000),
        correct: raw.correct === true || raw.correct === 'true',
        options: (Array.isArray(raw.options) ? raw.options.slice(0, 10) : []).map((option) => ({
            text: text(option && option.text, 1000),
            correct: Boolean(option && (option.correct === true || option.correct === 'true')),
        })),
    };
};

/**
 * The saved data is not trusted: each element is made from a new one and only the known fields are copied, with their type.
 *
 * @param {Object} raw
 * @returns {Object|null} The element, or null when it is not one.
 */
const blockFromDraft = (raw) => {
    if (!raw || !TYPES.includes(raw.type)) {
        return null;
    }
    const block = newBlock(raw.type);
    const allowed = {video: [MODE_FIXED], text: [MODE_AI, MODE_FIXED, MODE_HTML]};
    const modes = allowed[raw.type] || [MODE_AI, MODE_FIXED];
    block.mode = modes.includes(raw.mode) ? raw.mode : block.mode;
    ['instr', 'value', 'fixedtext', 'html', 'prompt', 'url', 'name'].forEach((field) => {
        block[field] = text(raw[field]);
    });
    block.kind = QUESTION_KINDS.includes(raw.kind) ? raw.kind : block.kind;
    block.question = question(raw.question);
    if (raw.uploaded === true && typeof raw.fileKey === 'string' && FILE_KEY.test(raw.fileKey)) {
        block.uploaded = true;
        block.fileKey = raw.fileKey;
        block.slot = Number.isInteger(raw.slot) && raw.slot >= 1 && raw.slot <= MAX_SLIDES ? raw.slot : null;
    }
    return block;
};

/**
 * @param {string} json What was saved.
 * @returns {{topic: string, lang: string, reference: Object|null, lost: boolean, model: Object}|null} The state to go on with,
 *     or null when the draft is not of this version or is not usable. A file whose key is not valid is dropped and counted as lost.
 */
export const fromDraft = (json) => {
    let raw;
    try {
        raw = JSON.parse(json);
    } catch (error) {
        return null;
    }
    if (!raw || raw.v !== DRAFT_VERSION || !raw.model || typeof raw.model !== 'object') {
        return null;
    }
    let lost = false;
    const slides = (Array.isArray(raw.model.slides) ? raw.model.slides : []).slice(0, MAX_SLIDES).map((slide) => ({
        blocks: (slide && Array.isArray(slide.blocks) ? slide.blocks : []).map((block) => {
            const restored = blockFromDraft(block);
            lost = lost || Boolean(block && block.uploaded && restored && !restored.uploaded);
            return restored;
        }).filter(Boolean),
    }));
    const whole = (value, fallback) => {
        return Number.isInteger(value) ? Math.min(Math.max(value, MIN_SLIDES), MAX_SLIDES) : fallback;
    };
    const ref = raw.reference;
    const valid = ref && typeof ref.key === 'string' && FILE_KEY.test(ref.key);
    const reference = valid ? {key: ref.key, name: text(ref.name, 255)} : null;
    return {
        topic: text(raw.topic, 4000),
        lang: text(raw.lang, 10),
        reference,
        lost: lost || Boolean(raw.reference && !reference),
        model: {
            mode: raw.model.mode === 'auto' ? 'auto' : 'manual',
            min: whole(raw.model.min, 5),
            max: whole(raw.model.max, 8),
            name: text(raw.model.name, 255),
            intro: text(raw.model.intro, 2000),
            current: Math.min(Math.max(Number(raw.model.current) || 0, 0), Math.max(slides.length - 1, 0)),
            slides,
        },
    };
};
