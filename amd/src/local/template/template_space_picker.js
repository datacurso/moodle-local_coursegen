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
 * The modal of "Add space for an activity": the activity chooser of the course page (search, tabs, help of each
 * type), where one click selects an activity and another unselects it; under it, each selected activity with its own
 * instruction (see template_space_picker_form).
 *
 * One activity is created by the AI (its name is optional: with none, the AI writes it), and a File can instead be
 * one the teacher uploads. Several activities make a space where the teacher chooses one when creating the course.
 *
 * @module     local_coursegen/local/template/template_space_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString, get_strings as getStrings} from 'core/str';
import {openActivityChooser} from 'local_coursegen/local/template/template_space_chooser';
import {
    focusInstruction, readPicker, refresh, region, showCard, tickedOptions,
} from 'local_coursegen/local/template/template_space_picker_form';
import {COMPONENT} from 'local_coursegen/local/template/constants';

const OPTION = '[data-region="chooser-option-container"]';
const OPTION_LINK = 'a[data-action="add-chooser-option"]';
const SELECTED = 'tpl-chooser-selected';

/**
 * Mark the selected activities in the chooser, in every tab and in the results of a search.
 *
 * @param {HTMLElement} root The modal.
 * @param {Set<string>} selected The module names selected.
 */
const markOptions = (root, selected) => {
    root.querySelectorAll(OPTION).forEach((option) => {
        const on = selected.has(option.dataset.internal);
        option.classList.toggle(SELECTED, on);
        option.setAttribute('aria-selected', String(on));
    });
};

/**
 * The part under the chooser and the footer with Cancel and Save, added to the modal of the chooser.
 *
 * @param {Object} modal The chooser modal.
 * @param {Object[]} items The chooser items.
 * @returns {Promise<void>}
 */
const addForm = async(modal, items) => {
    const options = items.map((item) => ({modname: item.name, title: item.title, icon: item.icon}));
    const [html, [cancel, save]] = await Promise.all([
        Templates.render('local_coursegen/template_space_picker_body', {options}),
        getStrings([{key: 'cancel', component: 'core'}, {key: 'savechanges', component: 'core'}]),
    ]);
    const form = document.createElement('div');
    // Not a .modal-body: the chooser gives its body a fixed height and no padding.
    form.className = 'tpl-space-picker-form border-top';
    form.innerHTML = html;
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" class="btn btn-secondary" data-action="cancel"></button>` +
        `<button type="button" class="btn btn-primary" data-action="save"></button>`;
    footer.querySelector('[data-action="cancel"]').textContent = cancel;
    footer.querySelector('[data-action="save"]').textContent = save;
    // After the body of the chooser, which the chooser draws again: never inside it.
    modal.getRoot()[0].querySelector('.modal-content').append(form, footer);
};

/**
 * Open the modal and resolve with what the admin saved, or null when it is closed without saving.
 *
 * @param {Object} params
 * @param {number} params.courseId The base course.
 * @param {string[]} params.supportedTypes Module names a space can be made for.
 * @returns {Promise<Object|null>} What readPicker returns.
 */
export const pickSpace = async({courseId, supportedTypes}) => {
    const {modal, items} = await openActivityChooser({courseId, supportedTypes});
    const [several, single, teacher] = await Promise.all([
        getString('template_teacher_space_instruction', COMPONENT),
        getString('template_space_instruction_placeholder_ai', COMPONENT),
        getString('template_space_instruction_placeholder', COMPONENT),
    ]);
    await addForm(modal, items);
    const placeholders = {several, single, teacher};
    const rootList = modal.getRoot();
    const root = rootList[0];
    const selected = new Set();
    const toggle = (modname) => {
        const on = !selected.has(modname);
        if (on) {
            selected.add(modname);
        } else {
            selected.delete(modname);
        }
        showCard(root, modname, on);
        markOptions(root, selected);
        refresh(root, placeholders);
        return on;
    };
    // A click on a type selects or unselects it instead of adding it.
    root.addEventListener('click', (event) => {
        const link = event.target.closest(OPTION_LINK);
        if (!link || !link.closest(OPTION)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const modname = link.closest(OPTION).dataset.internal;
        if (toggle(modname)) {
            focusInstruction(root, modname);
        }
    }, true);
    root.querySelectorAll(region('remove')).forEach((button) => {
        button.addEventListener('click', () => toggle(button.closest(region('option')).dataset.modname));
    });
    const update = () => refresh(root, placeholders);
    root.querySelectorAll(region('mode')).forEach((input) => input.addEventListener('change', update));
    root.querySelectorAll(region('instruction')).forEach((field) => field.addEventListener('input', update));
    // A search draws its results again: their marks are put back.
    new MutationObserver(() => markOptions(root, selected)).observe(modal.getBody()[0], {childList: true, subtree: true});
    refresh(root, placeholders);

    return new Promise((resolve) => {
        let saved = false;
        root.querySelector('.modal-footer [data-action="cancel"]').addEventListener('click', () => modal.hide());
        root.querySelector('.modal-footer [data-action="save"]').addEventListener('click', (event) => {
            if (event.currentTarget.disabled || !tickedOptions(root).length || saved) {
                return;
            }
            saved = true;
            resolve(readPicker(root, items));
            modal.hide();
        });
        rootList.on(ModalEvents.hidden, () => {
            if (!saved) {
                resolve(null);
            }
            modal.destroy();
        });
    });
};
