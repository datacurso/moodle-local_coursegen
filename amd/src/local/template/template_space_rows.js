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
 * DOM behaviour of a virtual space row: inserting one (the same markup
 * template_course_sections_row.mustache renders server-side, so a freshly
 * added row is indistinguishable from a saved one), editing it through the
 * shared space modal, and removing it. Scraping rows back into the save
 * payload lives with the other virtual rows, in template_instance_rows.js.
 *
 * @module     local_coursegen/local/template/template_space_rows
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    renderRowFragment,
    buildGapRow,
    dropGapBeforeAddRow,
    removeInstanceRow,
} from 'local_coursegen/local/template/template_instance_rows';
import {openSpaceModal} from 'local_coursegen/local/template/template_space_modal';
import {chooseActivityType} from 'local_coursegen/local/template/template_space_chooser';
import {closeInstanceMenu} from 'local_coursegen/local/template/template_instance_menu';
import {get_string as getString} from 'core/str';
import {prefetchStrings} from 'core/prefetch';

/** @type {number} Client-only counter for unique data-space-id values on unsaved rows. */
let nextTempId = 1;

/**
 * The 1/0 flag a requirement is stored as on a row and in the save payload.
 *
 * @param {boolean} required
 * @returns {number}
 */
const requiredFlagOf = (required) => {
    if (required) {
        return 1;
    }
    return 0;
};

/**
 * The language string key of a requirement label.
 *
 * @param {boolean} required
 * @returns {string}
 */
const requirementKeyOf = (required) => {
    if (required) {
        return 'template_space_required';
    }
    return 'template_space_optional';
};

/**
 * The badge text for a requirement: "Space · Required" / "Space · Optional".
 *
 * @param {boolean} required
 * @returns {Promise<string>}
 */
export const spaceBadgeText = async(required) => {
    const key = requirementKeyOf(required);
    const requirement = await getString(key, 'local_coursegen');
    return getString('template_space_badge', 'local_coursegen', requirement);
};

/**
 * Whether a gap row already sits immediately before the given element.
 *
 * @param {HTMLElement} beforeEl The row-gap or add-row the trigger belongs to.
 * @returns {boolean}
 */
const hasLeadingGap = (beforeEl) => {
    const previous = beforeEl.previousElementSibling;
    if (!previous) {
        return false;
    }
    return previous.classList.contains('tpl-row-gap');
};

/**
 * Insert a new space row (plus its own trailing gap) immediately before the
 * given element, adding a leading gap first when none precedes it.
 *
 * @param {HTMLElement} tbody The section's table body.
 * @param {HTMLElement} beforeEl The row-gap or add-row the trigger belongs to.
 * @param {Object} space {modname, name, iconurl, required, instruction}
 * @returns {Promise<HTMLElement>} The new space row.
 */
export const insertSpaceRow = async(tbody, beforeEl, space) => {
    if (!hasLeadingGap(beforeEl)) {
        const leadingGap = await buildGapRow();
        tbody.insertBefore(leadingGap, beforeEl);
    }

    const badge = await spaceBadgeText(space.required);
    const requiredvalue = requiredFlagOf(space.required);
    const tempId = nextTempId++;
    const context = {
        spaceid: 'new-' + tempId,
        name: space.name,
        typelabel: space.name,
        modname: space.modname,
        iconurl: space.iconurl,
        requiredvalue,
        badge,
        instruction: space.instruction,
        hasinstruction: space.instruction !== '',
    };
    const fragment = await renderRowFragment('local_coursegen/template_space_row', context);
    const row = fragment.querySelector('[data-for="spacerow"]');
    tbody.insertBefore(fragment, beforeEl);
    const trailingGap = await buildGapRow();
    tbody.insertBefore(trailingGap, beforeEl);
    dropGapBeforeAddRow(tbody);
    return row;
};

/**
 * Paint a new requirement and instruction onto an existing space row.
 *
 * @param {HTMLElement} row The row (data-for="spacerow").
 * @param {{required: boolean, instruction: string}} choice
 */
const applyChoiceToRow = async(row, choice) => {
    const flag = requiredFlagOf(choice.required);
    row.dataset.required = String(flag);
    const badgeEl = row.querySelector('[data-region="space-badge"]');
    badgeEl.textContent = await spaceBadgeText(choice.required);
    const instructionEl = row.querySelector('[data-region="space-instruction"]');
    instructionEl.textContent = choice.instruction;
    instructionEl.classList.toggle('d-none', choice.instruction === '');
};

/**
 * Insert the space the admin just configured for a freshly picked type.
 *
 * @param {HTMLElement} tbody The section's table body.
 * @param {HTMLElement} beforeEl The row-gap or add-row the menu opened from.
 * @param {Object} picked {modname, name, iconurl} of the picked type.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 * @param {{required: boolean, instruction: string}} choice What the modal saved.
 */
const saveNewSpace = async(tbody, beforeEl, picked, markDirty, choice) => {
    await insertSpaceRow(tbody, beforeEl, {...picked, ...choice});
    markDirty();
};

/**
 * Add a space for an activity at the position the menu opened from: pick the
 * activity type in the chooser, then say whether it is required and what the
 * professor has to provide, then insert the row.
 *
 * @param {HTMLElement} item The clicked "add a space" menu item.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const addSpace = async(item, state, markDirty) => {
    const beforeEl = item.closest('[data-region="row-gap"], [data-region="add-instance"]');
    const table = beforeEl.closest('table');
    const tbody = table.querySelector('tbody');
    const triggerEl = beforeEl.querySelector('[data-instance-menu-trigger]');
    closeInstanceMenu(triggerEl);

    const picked = await chooseActivityType({
        courseId: state.selectedCourseId,
        supportedTypes: state.supportedTypes,
    });
    if (picked === null) {
        return;
    }
    const onSave = saveNewSpace.bind(null, tbody, beforeEl, picked, markDirty);
    openSpaceModal({subject: picked.name, required: true, instruction: '', onSave});
};

/**
 * Apply the choice saved for an existing space row.
 *
 * @param {HTMLElement} row The row (data-for="spacerow").
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 * @param {{required: boolean, instruction: string}} choice What the modal saved.
 */
const saveEditedSpace = async(row, markDirty, choice) => {
    await applyChoiceToRow(row, choice);
    markDirty();
};

/**
 * Reopen the shared modal for one existing space row.
 *
 * @param {HTMLElement} row The row (data-for="spacerow").
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
const editSpaceRow = (row, markDirty) => {
    const nameCell = row.querySelector('td:nth-child(3)');
    const subject = nameCell.textContent.trim();
    const instructionEl = row.querySelector('[data-region="space-instruction"]');
    const instruction = instructionEl.textContent.trim();
    const onSave = saveEditedSpace.bind(null, row, markDirty);
    openSpaceModal({subject, required: row.dataset.required === '1', instruction, onSave});
};

/**
 * Bind the clicks of space rows: the badge and the edit icon reopen the
 * shared modal for that row; the remove icon removes it.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindSpaceRows = (container, markDirty) => {
    prefetchStrings('local_coursegen', [
        'template_space_badge',
        'template_space_required',
        'template_space_optional',
        'template_space_edit',
        'template_space_remove',
        'template_space_modal_title',
    ]);
    container.addEventListener('click', (e) => {
        const row = e.target.closest('[data-for="spacerow"]');
        if (!row) {
            return;
        }
        if (e.target.closest('[data-region="space-remove"]')) {
            removeInstanceRow(row);
            markDirty();
            return;
        }
        if (e.target.closest('[data-region="space-badge"], [data-region="space-edit"]')) {
            editSpaceRow(row, markDirty);
        }
    });
};
