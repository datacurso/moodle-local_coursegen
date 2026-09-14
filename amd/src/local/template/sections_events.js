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
 * - Per-row action select (data-region="activity-action"): its rendered
 *   value is the server-side default, so binding first SEEDS
 *   state.activityAction from it (the server render is the single source of
 *   truth for defaults), then keeps state in sync on change.
 * - Per-row template-scope select (data-region="template-scope"), and its
 *   "Template" badge (data-region="template-badge"): visible only while the
 *   row's action is "template", kept in sync on every action/scope change.
 * - Per-row selection checkboxes (data-region="activity-select") plus the
 *   single global bulk select (data-region="bulk-action") — see
 *   selection_bulk.js, bound from here.
 * - Section behavior selects (data-region="section-behavior"): their
 *   rendered value is the server-side default (the saved behavior in edit
 *   mode), so binding seeds state.sectionBehavior from it and keeps it in
 *   sync on change.
 *
 * State is the live object owned by init.js — mutated directly, never
 * passed through setState() (whose selectedCourseId handling would re-run
 * the whole config region render on every click).
 *
 * @module     local_coursegen/local/template/sections_events
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {typeSupportsModify} from './type_action_sync';
import {bindSelectionAndBulk} from './selection_bulk';

/** @type {boolean} Whether any config has been modified. */
let dirty = false;

/**
 * Mark the wizard as having unsaved changes.
 */
const markDirty = () => {
    if (!dirty) {
        dirty = true;
        window.addEventListener('beforeunload', onBeforeUnload);
    }
};

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
 * Drop the unsaved-changes protection after a real, successful save.
 *
 * This listener is OUR OWN raw beforeunload handler for the sections
 * controls (which live outside any watched form) — it is invisible to
 * core_form/changechecker, so init.js's resetAllFormDirtyStates() call
 * after a successful save cannot clear it: without this reset the browser
 * kept showing the "changes you made may not be saved" dialog on the
 * post-save redirect whenever any sections control had been touched.
 * A failed save never reaches this, so the protection stays.
 */
export const resetSectionsDirtyState = () => {
    dirty = false;
    window.removeEventListener('beforeunload', onBeforeUnload);
};

/**
 * Resolve the action a row may actually take: types the generator cannot
 * produce content for degrade modify/template to keep (their selects do not
 * even offer those options — same rule as the server-side defaults).
 *
 * @param {string} action Requested action.
 * @param {string} modname The row's module type.
 * @returns {string} The action to apply.
 */
const applicableAction = (action, modname) =>
    ((action === 'modify' || action === 'template') && !typeSupportsModify(modname)) ? 'keep' : action;

/**
 * Show/hide a row's "Template" badge and scope select based on its action,
 * and keep state.activityScope seeded for rows currently marked "template".
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {string} action The row's current action value.
 * @param {number} cmid The row's course module id.
 * @param {Object} state The live wizard state from init.js.
 */
const syncTemplateRowUi = (row, action, cmid, state) => {
    const istemplate = action === 'template';
    const badge = row.querySelector('[data-region="template-badge"]');
    const scopeselect = row.querySelector('[data-region="template-scope"]');
    if (badge) {
        badge.classList.toggle('d-none', !istemplate);
    }
    if (scopeselect) {
        scopeselect.classList.toggle('d-none', !istemplate);
    }
    if (istemplate) {
        state.activityScope[cmid] = scopeselect ? scopeselect.value : (state.activityScope[cmid] || 'course');
    }
};

/**
 * Bind events on the server-rendered review controls (no DOM injection).
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 */
export const bindServerRenderedControls = (container, state) => {
    // Row action selects: seed state from the server-rendered default, then
    // track every change. Each row's "Template" badge and scope select
    // (visible only while its action is "template") are kept in sync here.
    container.querySelectorAll('select[data-region="activity-action"]').forEach(select => {
        const cmid = parseInt(select.dataset.id);
        if (!cmid) {
            return;
        }
        const row = select.closest('[data-for="cmitem"]');
        state.activityAction[cmid] = select.value;
        if (row) {
            syncTemplateRowUi(row, select.value, cmid, state);
        }
        select.addEventListener('change', () => {
            state.activityAction[cmid] = select.value;
            if (row) {
                syncTemplateRowUi(row, select.value, cmid, state);
            }
            markDirty();
        });
    });

    // Per-row template-scope selects: seed state from the server-rendered
    // value, then track every change. Hidden rows still get seeded so a
    // scope chosen, then the action changed away and back, is not lost.
    container.querySelectorAll('select[data-region="template-scope"]').forEach(select => {
        const cmid = parseInt(select.dataset.id);
        if (!cmid) {
            return;
        }
        state.activityScope[cmid] = select.value;
        select.addEventListener('change', () => {
            state.activityScope[cmid] = select.value;
            markDirty();
        });
    });

    // Row selection checkboxes (three synced tiers) and the single global
    // bulk action bar — see selection_bulk.js.
    bindSelectionAndBulk(container, state, {applicableAction, syncTemplateRowUi, markDirty});

    // Section behavior selects (custom/keep/exclude): seed state from the
    // server-rendered value — the saved behavior in edit mode — then keep
    // it in sync on change, the same pattern as the row action selects.
    container.querySelectorAll('select[data-region="section-behavior"]').forEach(select => {
        const sid = parseInt(select.dataset.sid);
        if (!sid) {
            return;
        }
        state.sectionBehavior[sid] = select.value;
        select.addEventListener('change', () => {
            state.sectionBehavior[sid] = select.value;
            markDirty();
        });
    });
};
