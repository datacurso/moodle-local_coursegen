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
 * What the template of the Course Presentation editor needs, made from the slides.
 *
 * @module     local_coursegen/local/activityai/presentation_view
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as model from 'local_coursegen/local/activityai/presentation_model';

const {MODE_AI, MODE_FIXED, MODE_HTML, QUESTION_KINDS, TYPES, blockOf, markersOf, plain} = model;

/**
 * @param {Object} block
 * @param {Object} ui
 * @param {Object} strings
 * @returns {Object} One element, ready for the template.
 */
const blockContext = (block, ui, strings) => {
    const question = block.question;
    const kind = question ? question.type : block.kind;
    const context = {
        id: block.id,
        label: strings['pe_type_' + block.type],
        isAi: block.mode === MODE_AI,
        isFixed: block.mode === MODE_FIXED,
        isHtml: block.mode === MODE_HTML,
        canHtml: block.type === 'text',
        canFix: block.type !== 'video',
        instr: block.instr,
        placeholder: strings['pe_ph_' + block.type],
        busy: ui.busy === block.id,
        copyopen: ui.copyFor === block.id,
        value: block.value,
        fixedtext: block.fixedtext,
        html: block.html,
        markers: markersOf(block.html).length,
        prompt: block.prompt,
        uploaded: block.uploaded,
        name: block.name,
        url: block.url,
        qprompt: question ? question.prompt || '' : '',
        qmulti: kind === 'multi_choice',
        qtf: kind === 'true_false',
        qfill: kind === 'fill_in_the_blanks',
        qoptions: (question && question.options || []).map((option, index) => ({
            index, text: option.text || '', correct: option.correct === true || String(option.correct) === 'true',
        })),
        qtrue: question ? question.correct === true || String(question.correct) === 'true' : false,
        qtext: question && question.text ? question.text : '',
        kinds: QUESTION_KINDS.map((key) => ({key, label: strings['pe_kind_' + key], active: key === kind})),
        hasquestion: Boolean(question),
    };
    context['is' + block.type.charAt(0).toUpperCase() + block.type.slice(1)] = true;
    context.hasresult = block.mode === MODE_AI && Boolean(plain(block.value) || block.prompt || question);
    context.resulttext = block.type === 'text' ? plain(block.value) : block.value;
    return context;
};

/**
 * @param {Object} data The slides and the choices of the person.
 * @param {Object} ui What the screen is doing.
 * @param {Object} strings
 * @param {Function} clean Makes HTML safe.
 * @returns {Object}
 */
export const buildContext = (data, ui, strings, clean) => {
    const slides = data.slides;
    const total = slides.length;
    const current = slides[data.current];
    const context = {
        error: ui.error,
        topic: ui.topic,
        manual: data.mode === 'manual',
        auto: data.mode === 'auto',
        min: data.min,
        max: data.max,
        hasfile: Boolean(ui.filename),
        filename: ui.filename,
        busy: ui.busy !== '',
        name: data.name,
        languages: ui.languages,
        total,
        slides: slides.map((slide, index) => ({
            index,
            number: index + 1,
            current: index === data.current,
            title: blockOf(slide, 'title') && blockOf(slide, 'title').value || strings.pe_slide_label + ' ' + (index + 1),
        })),
    };
    if (current && data.mode === 'manual') {
        context.current = {
            number: data.current + 1,
            total,
            first: data.current === 0,
            last: data.current === total - 1,
            blocks: current.blocks.map((block) => {
                const item = blockContext(block, ui, strings);
                item.resulthtml = block.type === 'text' ? clean(block.value, false) : '';
                item.copytargets = slides.map((slide, index) => ({index, number: index + 1}))
                    .filter((target) => target.index !== data.current);
                return item;
            }),
            addchips: TYPES.filter((type) => !blockOf(current, type)).map((type) => ({type, label: strings['pe_type_' + type]})),
        };
    }
    return context;
};
