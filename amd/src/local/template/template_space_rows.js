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
import {COMPONENT, EVENT, NEW_ROW_PREFIX, REQUIRED_VALUE, STRING} from 'local_coursegen/local/template/constants';
import {CLASS, SELECTOR, TAG} from 'local_coursegen/local/template/dom_constants';

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
        return STRING.SPACE_REQUIRED;
    }
    return STRING.SPACE_OPTIONAL;
};

/**
 * The badge text for a requirement: "Space · Required" / "Space · Optional".
 *
 * @param {boolean} required
 * @returns {Promise<string>}
 */
export const spaceBadgeText = async(required) => {
    const key = requirementKeyOf(required);
    const requirement = await getString(key, COMPONENT);
    return getString(STRING.SPACE_BADGE, COMPONENT, requirement);
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
    return previous.classList.contains(CLASS.ROW_GAP);
};

/**
 * Insert a new space row (plus its own trailing gap) immediately before the
 * given element, adding a leading gap first when none precedes it.
 *
 * @param {HTMLElement} tbody The section's table body.
 * @param {HTMLElement} beforeEl The row-gap or add-row the trigger belongs to.
 * @param {Object} space {modname, name, icon, required, instruction}
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
        spaceid: NEW_ROW_PREFIX + tempId,
        name: space.name,
        typelabel: space.name,
        modname: space.modname,
        icon: space.icon,
        requiredvalue,
        badge,
        instruction: space.instruction,
        hasinstruction: space.instruction !== '',
    };
    const fragment = await renderRowFragment('local_coursegen/template_space_row', context);
    const row = fragment.querySelector(SELECTOR.SPACE_ROW);
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
    const instructionEl = row.querySelector(SELECTOR.SPACE_INSTRUCTION);
    instructionEl.textContent = choice.instruction;
    instructionEl.classList.toggle(CLASS.HIDDEN, choice.instruction === '');
};

/**
 * Insert the space the admin just configured for a freshly picked type.
 *
 * @param {HTMLElement} tbody The section's table body.
 * @param {HTMLElement} beforeEl The row-gap or add-row the menu opened from.
 * @param {Object} picked {modname, name, icon} of the picked type.
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
    const beforeEl = item.closest(SELECTOR.GAP_OR_ADD);
    const table = beforeEl.closest(TAG.TABLE);
    const tbody = table.querySelector(TAG.TABLE_BODY);
    const triggerEl = beforeEl.querySelector(SELECTOR.INSTANCE_MENU_TRIGGER);
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
    const instructionEl = row.querySelector(SELECTOR.SPACE_INSTRUCTION);
    const instruction = instructionEl.textContent.trim();
    const onSave = saveEditedSpace.bind(null, row, markDirty);
    openSpaceModal({subject, required: row.dataset.required === REQUIRED_VALUE, instruction, onSave});
};

/**
 * Handle a click on a space row: the badge and the edit icon reopen the
 * shared modal for that row; the remove icon removes it.
 *
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 * @param {MouseEvent} e The click.
 */
const handleSpaceRowClick = (markDirty, e) => {
    const row = e.target.closest(SELECTOR.SPACE_ROW);
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
};

/**
 * Bind the clicks of space rows.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindSpaceRows = (container, markDirty) => {
    prefetchStrings(COMPONENT, [
        STRING.SPACE_BADGE,
        STRING.SPACE_REQUIRED,
        STRING.SPACE_OPTIONAL,
        'template_space_edit',
        'template_space_remove',
        STRING.SPACE_MODAL_TITLE,
    ]);
    const onClick = handleSpaceRowClick.bind(null, markDirty);
    container.addEventListener(EVENT.CLICK, onClick);
};
