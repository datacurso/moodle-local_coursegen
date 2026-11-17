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
 * The state of the list of "Add space for an activity": which rows are selected, what opens under each one, the
 * summary in the footer and what the modal saves.
 *
 * Each selected activity keeps its own fields however many are selected: its instruction and its optional name, and,
 * for a File, who provides it (the AI, or the teacher who uploads it: the only type with both). One activity of the AI
 * needs its instruction; among several, each instruction is optional and the teacher chooses one of them.
 *
 * @module     local_coursegen/local/template/template_space_picker_form
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The type a teacher can upload in place of the AI. */
const UPLOADABLE = 'resource';

/**
 * The selector of a part of the modal.
 *
 * @param {string} name
 * @returns {string}
 */
export const region = (name) => `[data-region="local_coursegen/template/space-picker-${name}"]`;

/**
 * The selected rows, in the order of the list.
 *
 * @param {HTMLElement} root The modal.
 * @returns {HTMLElement[]}
 */
export const selectedRows = (root) => [...root.querySelectorAll(region('option'))]
    .filter((row) => row.querySelector(region('toggle')).getAttribute('aria-checked') === 'true');

/**
 * Select or unselect a row: its box is checked and its panel opens or closes right under it.
 *
 * @param {HTMLElement} row
 * @param {boolean} selected
 */
export const setSelected = (row, selected) => {
    row.querySelector(region('toggle')).setAttribute('aria-checked', String(selected));
    row.classList.toggle('is-selected', selected);
    row.querySelector(region('panel')).hidden = !selected;
};

/**
 * Whether a row is a File the teacher uploads.
 *
 * @param {HTMLElement} row
 * @returns {boolean}
 */
const rowUploads = (row) => row.dataset.modname === UPLOADABLE
    && row.querySelector(`${region('mode')}:checked`)?.value === 'teacher';

/**
 * Whether the one selected row is a File the teacher uploads.
 *
 * @param {HTMLElement[]} rows The selected rows.
 * @returns {boolean}
 */
const teacherUploads = (rows) => rows.length === 1 && rowUploads(rows[0]);

/**
 * Show in each selected row its own fields: the label and placeholder of its instruction, its name unless the teacher
 * uploads it, and who provides it for a File. Nothing is hidden because another row is selected.
 *
 * @param {HTMLElement[]} rows The selected rows.
 * @param {Object} text The texts of the labels and placeholders.
 */
const shapePanels = (rows, text) => {
    const one = rows.length === 1;
    rows.forEach((row) => {
        const uploads = rowUploads(row);
        const label = row.querySelector(region('label'));
        const field = row.querySelector(region('instruction'));
        let kind = 'several';
        if (uploads) {
            kind = 'teacher';
        } else if (one) {
            kind = 'single';
        }
        label.textContent = text[kind].label;
        field.placeholder = text[kind].placeholder;
        field.required = kind === 'single';
        row.querySelector(region('name-group')).hidden = uploads;
        const mode = row.querySelector(region('mode-group'));
        if (mode) {
            mode.hidden = false;
            row.querySelector(region('teacher-hint')).hidden = !uploads;
        }
    });
};

/**
 * Bring the modal up to date with the selection: the panels, the summary, the requirement and Save.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object} text {single, several, teacher: {label, placeholder}, summary: {none, ai, teacher, several},
 *     badge: {one, many}}.
 */
export const refresh = (root, text) => {
    const rows = selectedRows(root);
    const uploads = teacherUploads(rows);
    shapePanels(rows, text);
    let summary = text.summary.none;
    if (rows.length > 1) {
        summary = text.summary.several;
    } else if (rows.length === 1) {
        summary = uploads ? text.summary.teacher : text.summary.ai;
    }
    root.querySelector(region('summary')).textContent = summary;
    const badge = root.querySelector(region('badge'));
    badge.hidden = rows.length === 0;
    badge.textContent = rows.length === 1 ? text.badge.one : text.badge.many.replace('{$a}', String(rows.length));
    root.querySelector(region('required-group')).hidden = !(rows.length > 1 || uploads);
    const aiAlone = rows.length === 1 && !uploads;
    const missing = aiAlone && rows[0].querySelector(region('instruction')).value.trim() === '';
    root.querySelector('[data-action="save"]').disabled = rows.length === 0 || missing;
};

/**
 * What the modal says, in the shape the caller inserts.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object[]} items The chooser items.
 * @returns {Object} {kind: 'single', picked, choice} or {kind: 'several', options, titles, required}; each option
 *     is {modname, instruction, name, mode}.
 */
export const readPicker = (root, items) => {
    const rows = selectedRows(root);
    const required = root.querySelector(`${region('required')}:checked`)?.value === '1';
    const options = rows.map((row) => ({
        modname: row.dataset.modname,
        instruction: row.querySelector(region('instruction')).value.trim(),
        name: rowUploads(row) ? '' : row.querySelector(region('name')).value.trim(),
        mode: rowUploads(row) ? 'teacher' : 'ai',
    }));
    const itemOf = (modname) => items.find((item) => item.name === modname) || {title: modname, icon: ''};
    if (options.length > 1) {
        return {kind: 'several', options, titles: options.map((option) => itemOf(option.modname).title), required};
    }
    const item = itemOf(options[0].modname);
    const picked = {modname: options[0].modname, name: item.title, icon: item.icon};
    if (teacherUploads(rows)) {
        // The row of the space shows the type ("File"): no name of its own that would hide it.
        return {kind: 'single', picked, choice: {mode: 'teacher', required, instruction: options[0].instruction}};
    }
    const {name, instruction} = options[0];
    return {kind: 'single', picked, choice: {mode: 'ai', name, required: true, instruction}};
};

/**
 * Show the rows of the tab and the search, and say so when none is left.
 *
 * @param {HTMLElement} root The modal.
 * @param {string} tab all, activity or resource.
 * @param {string} query What the search says.
 */
export const filterRows = (root, tab, query) => {
    const needle = query.trim().toLocaleLowerCase();
    let shown = 0;
    root.querySelectorAll(region('option')).forEach((row) => {
        const inTab = tab === 'all' || row.dataset.kind === tab;
        const found = !needle || row.dataset.title.toLocaleLowerCase().includes(needle)
            || row.querySelector('.tpl-sp-desc').textContent.toLocaleLowerCase().includes(needle);
        row.hidden = !(inTab && found);
        shown += row.hidden ? 0 : 1;
    });
    root.querySelector(region('empty')).hidden = shown > 0;
};

/**
 * Put back what a space saved, to edit it: its activities selected, each with its instruction, name and mode, and
 * whether it is required.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object} preset {options: [{modname, instruction, name, mode}], required}.
 */
export const applyPreset = (root, preset) => {
    (preset.options || []).forEach((option) => {
        const row = root.querySelector(`${region('option')}[data-modname="${option.modname}"]`);
        if (!row) {
            return;
        }
        setSelected(row, true);
        row.querySelector(region('instruction')).value = option.instruction || '';
        row.querySelector(region('name')).value = option.name || '';
        const mode = row.querySelector(`${region('mode')}[value="${option.mode === 'teacher' ? 'teacher' : 'ai'}"]`);
        if (mode) {
            mode.checked = true;
        }
    });
    const required = root.querySelector(`${region('required')}[value="${preset.required === false ? '0' : '1'}"]`);
    if (required) {
        required.checked = true;
    }
};
