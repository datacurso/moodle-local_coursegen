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
 * A space for the teacher in the editor of a template: the activities the teacher can add in that place when they
 * create a course (they add one of them, and the AI writes it), an optional instruction for each, and whether the
 * teacher has to add one.
 *
 * @module     local_coursegen/local/template/template_teacher_space
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import {closeInstanceMenu} from 'local_coursegen/local/template/template_instance_menu';
import {fetchSupportedItems} from 'local_coursegen/local/template/template_space_chooser';
import {pickSpace} from 'local_coursegen/local/template/template_space_picker';
import Selectors from 'local_coursegen/local/template/selectors';
import {COMPONENT, STRING, TAG} from 'local_coursegen/local/template/constants';

const OPTION = '[data-region="local_coursegen/template/teacher-space-option"]';
const CHECK = '[data-region="local_coursegen/template/teacher-space-check"]';
const INSTRUCTION = '[data-region="local_coursegen/template/teacher-space-instruction"]';
const REQUIRED = '[data-region="local_coursegen/template/teacher-space-required"]';

/** @type {Object} The course and the activity types of the template, set when the editor binds its rows. */
const context = {courseId: 0, supportedTypes: []};

/**
 * Remember the course and the types of the template, for the modal opened from an existing row.
 *
 * @param {Object} state The live wizard state.
 */
export const setTeacherSpaceContext = (state) => {
    context.courseId = state.selectedCourseId;
    context.supportedTypes = state.supportedTypes;
};

/**
 * The options saved in a row.
 *
 * @param {HTMLElement|null} row
 * @returns {Object[]} Each {modname, instruction, name, mode}.
 */
const optionsOf = (row) => {
    try {
        const parsed = JSON.parse(row?.dataset.options || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
};

/**
 * Read what the modal says.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object[]} items The chooser items.
 * @returns {Object} {options: [{modname, instruction}], titles: string[], required: boolean}
 */
const readModal = (root, items) => {
    const options = [];
    const titles = [];
    root.querySelectorAll(OPTION).forEach((option) => {
        if (!option.querySelector(CHECK).checked) {
            return;
        }
        const modname = option.dataset.modname;
        options.push({modname, instruction: option.querySelector(INSTRUCTION).value.trim()});
        titles.push(items.find((item) => item.name === modname)?.title || modname);
    });
    const required = root.querySelector(`${REQUIRED}:checked`)?.value === '1';
    return {options, titles, required};
};

/**
 * Open the modal of a space for the teacher.
 *
 * @param {Object[]} saved The options saved so far, each {modname, instruction}.
 * @param {boolean} required Whether the space is required.
 * @param {Function} onSave Called with {options, titles, required} when at least one activity is chosen.
 * @returns {Promise<void>}
 */
const openModal = async(saved, required, onSave) => {
    const items = await fetchSupportedItems(context.courseId, context.supportedTypes);
    const options = items.map((item) => {
        const own = saved.find((option) => option.modname === item.name);
        return {modname: item.name, title: item.title, icon: item.icon, checked: !!own, instruction: own?.instruction || ''};
    });
    const modal = await ModalSaveCancel.create({
        title: getString('template_teacher_space_title', COMPONENT),
        body: Templates.render('local_coursegen/template_teacher_space_modal_body', {options, required}),
        removeOnClose: true,
    });
    const root = modal.getRoot();
    // At least one activity: a space the teacher could add nothing to would be no space.
    const refresh = () => {
        const save = root[0].querySelector('[data-action="save"]');
        if (save) {
            save.disabled = !root[0].querySelector(`${CHECK}:checked`);
        }
    };
    root.on(ModalEvents.bodyRendered, () => {
        root[0].querySelectorAll(OPTION).forEach((option) => {
            option.querySelector(CHECK).addEventListener('change', (event) => {
                option.querySelector(INSTRUCTION).classList.toggle('d-none', !event.target.checked);
                refresh();
            });
        });
        refresh();
    });
    root.on(ModalEvents.save, (event) => {
        const read = readModal(root[0], items);
        if (!read.options.length) {
            event.preventDefault();
            return;
        }
        onSave(read);
    });
    modal.show();
};

/**
 * Add a space for the teacher at the place the menu opened from.
 *
 * @param {HTMLElement} item The clicked menu item.
 * @param {Object} state The live wizard state.
 * @param {Function} markDirty Marks the template as changed.
 * @param {Function} insertSpaceRow Inserts a space row (template_space_rows).
 * @returns {Promise<void>}
 */
export const addTeacherSpace = async(item, state, markDirty, insertSpaceRow) => {
    const beforeEl = item.closest(Selectors.regions.gapOrAddInstance);
    const tbody = beforeEl.closest(TAG.TABLE).querySelector(TAG.TABLE_BODY);
    closeInstanceMenu(beforeEl.querySelector(Selectors.actions.instanceTrigger));
    await openNewTeacherSpace(state, tbody, beforeEl, markDirty, insertSpaceRow);
};

/**
 * Open the modal of a new space with several activities, and insert its row before the given element.
 *
 * @param {Object} state The live wizard state.
 * @param {HTMLElement} tbody The body of the table of the section.
 * @param {HTMLElement} beforeEl The row the new one goes before.
 * @param {Function} markDirty Marks the template as changed.
 * @param {Function} insertSpaceRow Inserts a space row (template_space_rows).
 * @returns {Promise<void>}
 */
export const openNewTeacherSpace = async(state, tbody, beforeEl, markDirty, insertSpaceRow) => {
    setTeacherSpaceContext(state);
    await openModal([], true, async(read) => {
        await insertSpaceRow(tbody, beforeEl, {
            name: await spaceName(read.titles),
            modname: read.options[0].modname,
            icon: '',
            required: read.required,
            instruction: '',
            options: read.options,
        });
        markDirty();
    });
};

/**
 * The name a space row shows: what the teacher can add there.
 *
 * @param {string[]} titles
 * @returns {Promise<string>}
 */
const spaceName = (titles) => getString('template_teacher_space_name', COMPONENT, titles.join(', '));

/**
 * Edit an existing space with several activities, in the modal of "Add space for an activity" with what it saved.
 *
 * @param {HTMLElement} row The space row.
 * @param {Function} markDirty Marks the template as changed.
 * @param {Function} onSingle Called with what the modal saved when only one activity is left.
 * @returns {Promise<void>}
 */
export const editTeacherSpace = async(row, markDirty, onSingle) => {
    const result = await pickSpace({
        courseId: context.courseId,
        supportedTypes: context.supportedTypes,
        preset: {options: optionsOf(row), required: row.dataset.required === '1'},
    });
    if (result === null) {
        return;
    }
    if (result.kind === 'single') {
        // One activity left: the row becomes what one activity is (one of the AI, or the teacher's File).
        await onSingle(result);
        return;
    }
    row.dataset.options = JSON.stringify(result.options);
    row.dataset.modname = result.options[0].modname;
    row.dataset.required = result.required ? '1' : '0';
    const name = row.querySelector(Selectors.regions.typeLabel);
    if (name) {
        name.textContent = await spaceName(result.titles);
    }
    const badge = row.querySelector(Selectors.regions.spaceBadge);
    if (badge) {
        const requirement = await getString(result.required ? STRING.SPACE_REQUIRED : STRING.SPACE_OPTIONAL, COMPONENT);
        badge.textContent = await getString(STRING.SPACE_BADGE, COMPONENT, requirement);
    }
    markDirty();
};
