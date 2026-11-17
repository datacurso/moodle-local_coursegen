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
 * The modal of "Add space for an activity": one activity per row at full width, with the search and the tabs of the
 * activity chooser. Selecting a row opens its instruction right under it, with the cursor in it; the footer says what
 * the selection makes and, when it applies, whether the space is required.
 *
 * @module     local_coursegen/local/template/template_space_picker
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';
import {fetchSupportedItems} from 'local_coursegen/local/template/template_space_chooser';
import {
    filterRows, press, readPicker, refresh, region, selectedRows, setSelected,
} from 'local_coursegen/local/template/template_space_picker_form';
import {COMPONENT} from 'local_coursegen/local/template/constants';

/** @type {number} The archetype the web service reports for a resource. */
const ARCHETYPE_RESOURCE = 1;

/**
 * The first sentence of the help of a type, as plain text: the line under its name.
 *
 * @param {string} help The help, as HTML.
 * @returns {string}
 */
const summaryOf = (help) => {
    const holder = document.createElement('div');
    holder.innerHTML = help || '';
    const text = holder.textContent.replace(/\s+/g, ' ').trim();
    const end = text.search(/[.!?](\s|$)/);
    return end > 0 ? text.slice(0, end + 1) : text;
};

/**
 * The texts the modal changes as the selection changes.
 *
 * @returns {Promise<Object>}
 */
const loadTexts = async() => {
    const keys = [
        'template_add_space', 'template_space_picker_label_ai', 'template_space_instruction_placeholder_ai',
        'template_space_picker_label_ai_optional', 'template_space_picker_placeholder_several',
        'template_space_picker_label_teacher', 'template_space_picker_placeholder_teacher',
        'template_space_picker_types', 'template_space_picker_one_ai', 'template_space_picker_one_teacher',
        'template_space_picker_several',
    ];
    const s = await getStrings(keys.map((key) => ({key, component: COMPONENT})));
    return {
        title: s[0],
        single: {label: s[1], placeholder: s[2]},
        several: {label: s[3], placeholder: s[4]},
        teacher: {label: s[5], placeholder: s[6]},
        summary: {none: s[7], ai: s[8], teacher: s[9], several: s[10]},
    };
};

/**
 * Wire the rows, the tabs and the search of the modal.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object} text What loadTexts returns.
 */
const bindList = (root, text) => {
    const update = () => refresh(root, text);
    const view = {tab: 'all', query: ''};
    root.querySelectorAll(region('toggle')).forEach((toggle) => toggle.addEventListener('click', () => {
        const row = toggle.closest(region('option'));
        const selected = toggle.getAttribute('aria-checked') !== 'true';
        setSelected(row, selected);
        update();
        if (selected) {
            // The cursor in its instruction, and the whole panel in view above the footer.
            row.querySelector(region('instruction')).focus({preventScroll: true});
            row.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        }
    }));
    root.querySelectorAll(region('info')).forEach((info) => info.addEventListener('click', () => {
        const help = info.closest(region('option')).querySelector(region('helptext'));
        help.hidden = !help.hidden;
        info.setAttribute('aria-expanded', String(!help.hidden));
    }));
    root.querySelectorAll(`${region('mode')}, ${region('required')}`).forEach((button) => button.addEventListener('click', () => {
        press(button);
        update();
    }));
    root.querySelectorAll(region('instruction')).forEach((field) => field.addEventListener('input', update));
    root.querySelectorAll(region('tab')).forEach((tab) => tab.addEventListener('click', () => {
        root.querySelectorAll(region('tab')).forEach((other) => {
            other.classList.toggle('active', other === tab);
            other.setAttribute('aria-selected', String(other === tab));
        });
        view.tab = tab.dataset.tab;
        filterRows(root, view.tab, view.query);
    }));
    root.querySelector(region('search')).addEventListener('input', (event) => {
        view.query = event.target.value;
        filterRows(root, view.tab, view.query);
    });
    update();
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
    const [items, text] = await Promise.all([fetchSupportedItems(courseId, supportedTypes), loadTexts()]);
    const options = items.map((item) => ({
        modname: item.name,
        title: item.title,
        summary: summaryOf(item.help),
        help: item.help,
        icon: item.icon,
        purpose: item.purpose || '',
        kind: Number(item.archetype) === ARCHETYPE_RESOURCE ? 'resource' : 'activity',
        uploadable: item.name === 'resource',
    }));
    // Both drawn before the modal opens, so the list is wired once its footer is there too.
    const [body, footer] = await Promise.all([
        Templates.render('local_coursegen/template_space_picker_body', {options}),
        Templates.render('local_coursegen/template_space_picker_footer', {}),
    ]);
    // Large, and with no scroll of its own: the list grows with the page and the footer stays at the bottom.
    const modal = await Modal.create({
        title: text.title,
        body,
        footer,
        large: true,
        scrollable: false,
        templateContext: {classes: 'tpl-space-picker-modal'},
        removeOnClose: true,
    });
    const rootList = modal.getRoot();
    const root = rootList[0];
    bindList(root, text);
    return new Promise((resolve) => {
        let settled = false;
        rootList.on(ModalEvents.shown, () => root.querySelector(region('search')).focus());
        rootList.on('click', '[data-action="cancel"]', () => modal.hide());
        rootList.on('click', '[data-action="save"]', (event) => {
            // Nothing selected, or one activity of the AI with no instruction: Save does nothing, whatever enabled it.
            refresh(root, text);
            if (settled || event.currentTarget.disabled || !selectedRows(root).length) {
                return;
            }
            settled = true;
            resolve(readPicker(root, items));
            modal.hide();
        });
        // However the modal closes without a save, the caller is told, so "Add space" never stays blocked.
        rootList.on(ModalEvents.hidden, () => {
            if (!settled) {
                settled = true;
                resolve(null);
            }
        });
        modal.show();
    });
};
