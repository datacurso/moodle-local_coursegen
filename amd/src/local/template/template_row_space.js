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
 * Per-row "Space for the professor" state and the modal flows that change
 * it, for an existing activity marked to be replaced by the professor.
 * Split out of sections_events.js so that module stays focused on binding
 * the review's controls, the same way template_row_scope.js does for the
 * "template" action.
 *
 * @module     local_coursegen/local/template/template_row_space
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openSpaceModal} from 'local_coursegen/local/template/template_space_modal';

/**
 * Reflect a row's space status in the DOM: the row highlight, the badge's
 * visibility and label, and the instruction under the name. Never opens the
 * modal — this only paints state that is already decided.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {boolean} isspace Whether the row is currently marked "space".
 * @param {number} cmid The row's course module id.
 * @param {Object} state The live wizard state from init.js.
 */
export const applySpaceVisual = (row, isspace, cmid, state) => {
    row.classList.toggle('tpl-row-space', isspace);
    const tag = row.querySelector('[data-region="space-tag"]');
    const instructionEl = row.querySelector('[data-region="space-row-instruction"]');
    if (!tag || !instructionEl) {
        return;
    }
    tag.classList.toggle('d-none', !isspace);
    const space = state.activitySpace[cmid] || {required: true, instruction: ''};
    if (isspace) {
        tag.textContent = space.required ? tag.dataset.badgeRequired : tag.dataset.badgeOptional;
        instructionEl.textContent = space.instruction;
    }
    instructionEl.classList.toggle('d-none', !isspace || space.instruction === '');
};

/**
 * Open the shared space modal for one row whose action select was just
 * changed TO "space". Saving stores the choice and marks the row; cancelling
 * (in any way) reverts the select to its prior value.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {HTMLSelectElement} select The row's action select.
 * @param {number} cmid The row's course module id.
 * @param {string} prioraction The action selected immediately before this change.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} onResolved (finalAction) => void, called once the modal closes.
 */
export const openSpaceModalForNewSelection = (row, select, cmid, prioraction, state, onResolved) => {
    const tag = row.querySelector('[data-region="space-tag"]');
    const current = state.activitySpace[cmid] || {required: true, instruction: ''};
    openSpaceModal({
        subject: tag ? tag.dataset.name : '',
        required: current.required,
        instruction: current.instruction,
        onSave: (choice) => {
            state.activitySpace[cmid] = choice;
            state.activityAction[cmid] = 'space';
            applySpaceVisual(row, true, cmid, state);
            onResolved('space');
        },
        onCancel: () => {
            select.value = prioraction;
            state.activityAction[cmid] = prioraction;
            applySpaceVisual(row, false, cmid, state);
            onResolved(prioraction);
        },
    });
};

/**
 * Bind the click handler that reopens the space modal from a row's badge,
 * to change an already-marked row's requirement or instruction. Cancelling
 * leaves the row exactly as it was.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindSpaceTagClicks = (container, state, markDirty) => {
    container.addEventListener('click', (e) => {
        const tag = e.target.closest('[data-region="space-tag"]');
        if (!tag) {
            return;
        }
        const cmid = parseInt(tag.dataset.id);
        const row = tag.closest('[data-for="cmitem"]');
        if (!cmid || !row) {
            return;
        }
        const current = state.activitySpace[cmid] || {required: true, instruction: ''};
        openSpaceModal({
            subject: tag.dataset.name,
            required: current.required,
            instruction: current.instruction,
            onSave: (choice) => {
                state.activitySpace[cmid] = choice;
                applySpaceVisual(row, true, cmid, state);
                markDirty();
            },
        });
    });
};
