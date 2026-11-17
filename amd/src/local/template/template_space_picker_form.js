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
 * One selected activity is created by the AI and needs its instruction (with an optional name); a File can instead be
 * one the teacher uploads, and then the footer asks whether it is required. Several activities make a space where the
 * teacher chooses one, each instruction optional.
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
 * Whether the one selected row is a File the teacher uploads.
 *
 * @param {HTMLElement[]} rows The selected rows.
 * @returns {boolean}
 */
const teacherUploads = (rows) => rows.length === 1 && rows[0].dataset.modname === UPLOADABLE
    && rows[0].querySelector(`${region('mode')}[aria-pressed="true"]`)?.dataset.mode === 'teacher';

/**
 * Show in each selected row what applies to the selection: the label and placeholder of its instruction, the name
 * of one activity, who provides one File.
 *
 * @param {HTMLElement[]} rows The selected rows.
 * @param {Object} text The texts of the labels and placeholders.
 */
const shapePanels = (rows, text) => {
    const one = rows.length === 1;
    const uploads = teacherUploads(rows);
    rows.forEach((row) => {
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
        row.querySelector(region('name-group')).hidden = !one || uploads;
        const mode = row.querySelector(region('mode-group'));
        if (mode) {
            mode.hidden = !one;
            row.querySelector(region('teacher-hint')).hidden = !uploads;
        }
    });
};

/**
 * Bring the modal up to date with the selection: the panels, the summary, the requirement and Save.
 *
 * @param {HTMLElement} root The modal.
 * @param {Object} text {single, several, teacher: {label, placeholder}, summary: {none, ai, teacher, several}}.
 */
export const refresh = (root, text) => {
    const rows = selectedRows(root);
    const uploads = teacherUploads(rows);
    shapePanels(rows, text);
    let summary = text.summary.none;
    if (rows.length > 1) {
        summary = text.summary.several.replace('{$a}', String(rows.length));
    } else if (rows.length === 1) {
        summary = uploads ? text.summary.teacher : text.summary.ai;
    }
    root.querySelector(region('summary')).textContent = summary;
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
 * @returns {Object} {kind: 'single', picked, choice} or {kind: 'several', options, titles, required}.
 */
export const readPicker = (root, items) => {
    const rows = selectedRows(root);
    const required = root.querySelector(`${region('required')}[aria-pressed="true"]`)?.dataset.value === '1';
    const options = rows.map((row) => ({
        modname: row.dataset.modname,
        instruction: row.querySelector(region('instruction')).value.trim(),
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
    const name = rows[0].querySelector(region('name')).value.trim();
    return {kind: 'single', picked, choice: {mode: 'ai', name, required: true, instruction: options[0].instruction}};
};

/**
 * Press one button of a group of two (who provides a File, required or optional).
 *
 * @param {HTMLElement} button
 */
export const press = (button) => {
    button.parentElement.querySelectorAll('button').forEach((other) => {
        const on = other === button;
        other.classList.toggle('active', on);
        other.setAttribute('aria-pressed', String(on));
    });
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
