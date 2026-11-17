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
 * The part of "Add space for an activity" under the activity chooser: each selected activity with its instruction,
 * and what applies to them (the name of one, who provides a File, whether the space is required).
 *
 * @module     local_coursegen/local/template/template_space_picker_form
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The type a teacher can upload in place of the AI. */
const UPLOADABLE = 'resource';

/**
 * The selector of a part of the form.
 *
 * @param {string} name
 * @returns {string}
 */
export const region = (name) => `[data-region="local_coursegen/template/space-picker-${name}"]`;

/**
 * The selected activities, in the order of the list.
 *
 * @param {HTMLElement} root The modal.
 * @returns {HTMLElement[]}
 */
export const tickedOptions = (root) => [...root.querySelectorAll(region('option'))].filter((option) => !option.hidden);

/**
 * Show or hide the card of one activity. Call {@see refresh} after it.
 *
 * @param {HTMLElement} root The modal.
 * @param {string} modname
 * @param {boolean} selected
 */
export const showCard = (root, modname, selected) => {
    const card = root.querySelector(`${region('option')}[data-modname="${modname}"]`);
    if (card) {
        card.hidden = !selected;
    }
};

/**
 * Put the cursor in the instruction of an activity just selected, once its card is shown.
 *
 * @param {HTMLElement} root The modal.
 * @param {string} modname
 */
export const focusInstruction = (root, modname) => {
    const field = root.querySelector(`${region('option')}[data-modname="${modname}"] ${region('instruction')}`);
    field?.focus({preventScroll: true});
};

/**
 * Whether the admin chose that the teacher uploads the one File ticked.
 *
 * @param {HTMLElement} root The modal.
 * @returns {boolean}
 */
const teacherUploads = (root) => {
    const ticked = tickedOptions(root);
    return ticked.length === 1 && ticked[0].dataset.modname === UPLOADABLE
        && root.querySelector(`${region('mode')}:checked`)?.value === 'teacher';
};

/**
 * Show what belongs to what is ticked: the name for one activity, who provides it for one File, the requirement for a
 * File the teacher uploads or for several activities, and the help for several.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object} placeholders {several, single, teacher}: see {@see placeholderOf}.
 */
export const refresh = (root, placeholders) => {
    const ticked = tickedOptions(root);
    const one = ticked.length === 1;
    const uploads = teacherUploads(root);
    root.querySelector(region('help')).hidden = ticked.length < 2;
    root.querySelector(region('name-group')).hidden = !one || uploads;
    root.querySelector(region('mode-group')).hidden = !(one && ticked[0].dataset.modname === UPLOADABLE);
    root.querySelector(region('required-group')).hidden = !(ticked.length > 1 || uploads);
    // One activity the AI creates needs its instruction: it is all the AI has to know what to create.
    const aiAlone = one && !uploads;
    root.querySelector(region('selected')).hidden = ticked.length === 0;
    root.querySelector(region('empty')).hidden = ticked.length > 0;
    root.querySelectorAll(region('option')).forEach((option) => {
        const field = option.querySelector(region('instruction'));
        field.required = aiAlone && option === ticked[0];
        field.placeholder = placeholderOf(placeholders, option === ticked[0] && one, uploads);
    });
    const save = root.querySelector('[data-action="save"]');
    save.disabled = ticked.length === 0 || (aiAlone && ticked[0].querySelector(region('instruction')).value.trim() === '');
};

/**
 * The placeholder of the instruction of an activity: what the teacher must upload, how the one activity the AI
 * creates must be, or the optional instruction of one of several.
 *
 * @param {Object} placeholders {several, single, teacher}.
 * @param {boolean} alone It is the one activity ticked.
 * @param {boolean} uploads The teacher uploads it.
 * @returns {string}
 */
const placeholderOf = (placeholders, alone, uploads) => {
    if (!alone) {
        return placeholders.several;
    }
    return uploads ? placeholders.teacher : placeholders.single;
};

/**
 * What the modal says, in the shape the caller inserts.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object[]} items The chooser items.
 * @returns {Object} {kind: 'single', picked, choice} or {kind: 'several', options, titles, required}.
 */
export const readPicker = (root, items) => {
    const ticked = tickedOptions(root);
    const required = root.querySelector(`${region('required')}:checked`)?.value === '1';
    const options = ticked.map((option) => ({
        modname: option.dataset.modname,
        instruction: option.querySelector(region('instruction')).value.trim(),
    }));
    const itemOf = (modname) => items.find((item) => item.name === modname) || {title: modname, icon: ''};
    if (options.length > 1) {
        return {kind: 'several', options, titles: options.map((option) => itemOf(option.modname).title), required};
    }
    const item = itemOf(options[0].modname);
    const picked = {modname: options[0].modname, name: item.title, icon: item.icon};
    if (teacherUploads(root)) {
        // The row of the space shows the type ("File"): no name of its own that would hide it.
        return {kind: 'single', picked, choice: {mode: 'teacher', required, instruction: options[0].instruction}};
    }
    const name = root.querySelector(region('name')).value.trim();
    return {kind: 'single', picked, choice: {mode: 'ai', name, required: true, instruction: options[0].instruction}};
};
