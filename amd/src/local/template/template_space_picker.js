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
 * The modal of "Add space for an activity": the admin ticks one activity or several, each with its own instruction.
 *
 * One activity is created by the AI (its name is optional: with none, the AI writes it), and a File can instead be
 * one the teacher uploads. Several activities make a space where the teacher chooses one when creating the course.
 *
 * @module     local_coursegen/local/template/template_space_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import {fetchSupportedItems} from 'local_coursegen/local/template/template_space_chooser';
import {COMPONENT} from 'local_coursegen/local/template/constants';

/** The type a teacher can upload in place of the AI. */
const UPLOADABLE = 'resource';

const region = (name) => `[data-region="local_coursegen/template/space-picker-${name}"]`;

/**
 * The ticked activities, in the order of the list.
 *
 * @param {HTMLElement} root The modal.
 * @returns {HTMLElement[]}
 */
const tickedOptions = (root) => [...root.querySelectorAll(region('option'))]
    .filter((option) => option.querySelector(region('check')).checked);

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
const refresh = (root, placeholders) => {
    const ticked = tickedOptions(root);
    const one = ticked.length === 1;
    const uploads = teacherUploads(root);
    root.querySelector(region('help')).hidden = ticked.length < 2;
    root.querySelector(region('name-group')).hidden = !one || uploads;
    root.querySelector(region('mode-group')).hidden = !(one && ticked[0].dataset.modname === UPLOADABLE);
    root.querySelector(region('required-group')).hidden = !(ticked.length > 1 || uploads);
    // One activity the AI creates needs its instruction: it is all the AI has to know what to create.
    const aiAlone = one && !uploads;
    root.querySelectorAll(region('option')).forEach((option) => {
        const field = option.querySelector(region('instruction'));
        field.classList.toggle('d-none', !option.querySelector(region('check')).checked);
        field.required = aiAlone && option === ticked[0];
        field.placeholder = placeholderOf(placeholders, option === ticked[0] && one, uploads);
    });
    const save = root.querySelector('[data-action="save"]');
    if (save) {
        save.disabled = ticked.length === 0 || (aiAlone && ticked[0].querySelector(region('instruction')).value.trim() === '');
    }
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
const readPicker = (root, items) => {
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

/**
 * Open the modal and resolve with what the admin saved, or null when it is closed without saving.
 *
 * @param {Object} params
 * @param {number} params.courseId The base course.
 * @param {string[]} params.supportedTypes Module names a space can be made for.
 * @returns {Promise<Object|null>} What {@see readPicker} returns.
 */
export const pickSpace = async({courseId, supportedTypes}) => {
    const items = await fetchSupportedItems(courseId, supportedTypes);
    const [title, several, single, teacher] = await Promise.all([
        getString('template_add_space', COMPONENT),
        getString('template_teacher_space_instruction', COMPONENT),
        getString('template_space_instruction_placeholder_ai', COMPONENT),
        getString('template_space_instruction_placeholder', COMPONENT),
    ]);
    const options = items.map((item) => ({modname: item.name, title: item.title, icon: item.icon}));
    const modal = await ModalSaveCancel.create({
        title,
        body: Templates.render('local_coursegen/template_space_picker_body', {options}),
        removeOnClose: true,
    });
    const rootList = modal.getRoot();
    const root = rootList[0];
    const placeholders = {several, single, teacher};
    return new Promise((resolve) => {
        let saved = false;
        rootList.on(ModalEvents.bodyRendered, () => {
            root.querySelectorAll(`${region('check')}, ${region('mode')}`).forEach((input) => {
                input.addEventListener('change', () => refresh(root, placeholders));
            });
            root.querySelectorAll(region('instruction')).forEach((field) => {
                field.addEventListener('input', () => refresh(root, placeholders));
            });
            refresh(root, placeholders);
        });
        rootList.on(ModalEvents.save, (event) => {
            if (root.querySelector('[data-action="save"]')?.disabled || !tickedOptions(root).length) {
                event.preventDefault();
                return;
            }
            saved = true;
            resolve(readPicker(root, items));
        });
        rootList.on(ModalEvents.hidden, () => {
            if (!saved) {
                resolve(null);
            }
        });
        modal.show();
    });
};
