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
 * Event bindings for the server-rendered "Course sections" review controls.
 *
 * Bound again after EVERY render of the review (initial page load and each
 * AJAX course switch — see step_sections.js, the only caller), since a
 * course switch replaces the whole container's innerHTML. The controls:
 *
 * - Per-row action select (data-region="activity-action"): keep intact or
 *   modify with AI. Its rendered value is the server-side default (the saved
 *   action in edit mode), so binding first SEEDS state.activityAction from it
 *   (the server render is the single source of truth), then keeps state in
 *   sync on change. Choosing "ai" shows the row's instruction box.
 * - Per-row instruction textarea (data-region="activity-instruction"): the
 *   optional text telling the AI what to do with that activity; its rendered
 *   value seeds state.activityInstruction. An instruction typed for an
 *   activity that is later set back to "keep" stays in memory but is not saved.
 * - Per-row selection checkboxes plus the single global bulk select — see
 *   selection_bulk.js, bound from here.
 *
 * State is the live object owned by init.js — mutated directly, never
 * passed through setState() (whose selectedCourseId handling would re-run
 * the whole config region render on every click).
 *
 * @module     local_coursegen/local/template/sections_events
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {bindSelectionAndBulk} from 'local_coursegen/local/template/selection_bulk';
import {applyRowAction} from 'local_coursegen/local/template/row_action';
import Selectors from 'local_coursegen/local/template/selectors';
import {EVENT} from 'local_coursegen/local/template/constants';

/** @type {boolean} Whether any config has been modified. */
let dirty = false;

/**
 * Handler for beforeunload event.
 *
 * @param {Event} e
 */
const onBeforeUnload = (e) => {
    e.preventDefault();
    // Some browsers still require the legacy returnValue assignment.
    e.returnValue = '';
};

/**
 * Mark the editor as having unsaved changes.
 */
const markDirty = () => {
    if (!dirty) {
        dirty = true;
        window.addEventListener(EVENT.BEFORE_UNLOAD, onBeforeUnload);
    }
};

/**
 * Drop the unsaved-changes protection after a real, successful save.
 *
 * This listener is OUR OWN raw beforeunload handler for the sections
 * controls (which live outside any watched form) — it is invisible to
 * core_form/changechecker, so the resetAllFormDirtyStates() call after a
 * successful save cannot clear it. A failed save never reaches this, so the
 * protection stays.
 */
export const resetSectionsDirtyState = () => {
    dirty = false;
    window.removeEventListener(EVENT.BEFORE_UNLOAD, onBeforeUnload);
};

/**
 * React to a change of a row's action select.
 *
 * @param {Object} ctx The row's binding context {row, select, state}.
 */
const handleActionChange = (ctx) => {
    applyRowAction(ctx.row, ctx.select.value, ctx.state);
    markDirty();
};

/**
 * Seed one row's action from its server-rendered select, then track changes.
 *
 * @param {HTMLSelectElement} select The row's action select.
 * @param {Object} state The live editor state from init.js.
 */
const bindActivityActionSelect = (select, state) => {
    const row = select.closest(Selectors.rows.activity);
    if (!row) {
        return;
    }
    applyRowAction(row, select.value, state);
    const ctx = {row, select, state};
    const onChange = handleActionChange.bind(null, ctx);
    select.addEventListener(EVENT.CHANGE, onChange);
};

/**
 * Bind every row action select of the review.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live editor state from init.js.
 */
const bindActivityActionSelects = (container, state) => {
    const selects = container.querySelectorAll(Selectors.regions.activityActionSelect);
    for (const select of selects) {
        bindActivityActionSelect(select, state);
    }
};

/**
 * React to typing in a row's instruction textarea.
 *
 * @param {Object} ctx The field's binding context {field, cmid, state}.
 */
const handleInstructionInput = (ctx) => {
    ctx.state.activityInstruction[ctx.cmid] = ctx.field.value;
    markDirty();
};

/**
 * Seed one row's instruction from its server-rendered textarea, then track typing.
 *
 * @param {HTMLTextAreaElement} field The row's instruction textarea.
 * @param {Object} state The live editor state from init.js.
 */
const bindInstructionField = (field, state) => {
    const cmid = parseInt(field.dataset.id, 10);
    if (!cmid) {
        return;
    }
    state.activityInstruction[cmid] = field.value;
    const ctx = {field, cmid, state};
    const onInput = handleInstructionInput.bind(null, ctx);
    field.addEventListener(EVENT.INPUT, onInput);
};

/**
 * Bind every row instruction textarea of the review.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live editor state from init.js.
 */
const bindInstructionFields = (container, state) => {
    const fields = container.querySelectorAll(Selectors.regions.activityInstructionField);
    for (const field of fields) {
        bindInstructionField(field, state);
    }
};

/**
 * Bind events on the server-rendered review controls (no DOM injection).
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live editor state from init.js.
 */
export const bindServerRenderedControls = (container, state) => {
    bindActivityActionSelects(container, state);
    bindInstructionFields(container, state);
    // Row selection checkboxes (three synced tiers) and the single global
    // bulk action bar — see selection_bulk.js.
    bindSelectionAndBulk(container, state, {markDirty});
};
