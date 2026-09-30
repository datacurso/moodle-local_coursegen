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
 *   truth for defaults), then keeps state in sync on change. Changing it TO
 *   "template" opens the shared scope modal (template_scope_modal.js)
 *   instead of applying the action immediately; changing it away from
 *   "template" clears the row's visual signal with no modal involved.
 * - Changing a row's action TO "space" opens the shared space modal
 *   (template_row_space.js): required or optional, and what the professor has
 *   to provide. Its clickable badge (data-region="space-tag") reopens it.
 * - Per-row clickable "Template" tag (data-region="template-tag"): visible
 *   only while the row's action is "template", reopens the same modal to
 *   change an already-set scope.
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

import {typeSupportsModify} from 'local_coursegen/local/template/type_action_sync';
import {bindSelectionAndBulk} from 'local_coursegen/local/template/selection_bulk';
import {
    applyTemplateVisual,
    openScopeModalForNewSelection,
    confirmUnmarkTemplate,
    bindTemplateTagClicks,
} from 'local_coursegen/local/template/template_row_scope';
import {
    applySpaceVisual,
    openSpaceModalForNewSelection,
    bindSpaceTagClicks,
} from 'local_coursegen/local/template/template_row_space';
import {bindInstanceInserts} from 'local_coursegen/local/template/template_instance_events';
import {bindNameEditing} from 'local_coursegen/local/template/template_instance_name_edit';

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
const applicableAction = (action, modname) => {
    const requestsmodify = action === 'modify' || action === 'template';
    if (requestsmodify && !typeSupportsModify(modname)) {
        return 'keep';
    }
    return action;
};

/**
 * Keep the visual of a row in step with an action applied in bulk. The bulk
 * bar never sets "space", so whatever it applies to a row also clears that
 * row's space visual.
 *
 * @param {HTMLElement} row The row the bulk action was applied to.
 * @param {boolean} istemplate Whether the row is now a template mold.
 * @param {number} cmid The row's course module id.
 * @param {Object} rowstate The live wizard state from init.js.
 */
const applyRowVisual = (row, istemplate, cmid, rowstate) => {
    applyTemplateVisual(row, istemplate, cmid, rowstate);
    applySpaceVisual(row, false, cmid, rowstate);
};

/**
 * Seed one row's action from its server-rendered select, then track changes.
 *
 * Changing TO "template" opens the scope modal instead of applying the action
 * inline; the row's tag and highlight only ever reflect an already-resolved
 * decision.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {HTMLSelectElement} select The row's action select.
 * @param {Object} state The live wizard state from init.js.
 */
const bindActivityActionSelect = (container, select, state) => {
    const cmid = parseInt(select.dataset.id);
    if (!cmid) {
        return;
    }
    const row = select.closest('[data-for="cmitem"]');
    let prioraction = select.value;
    state.activityAction[cmid] = select.value;
    if (row) {
        applyTemplateVisual(row, select.value === 'template', cmid, state);
        applySpaceVisual(row, select.value === 'space', cmid, state);
    }
    select.addEventListener('change', () => {
        const action = select.value;
        const before = prioraction;
        state.activityAction[cmid] = action;
        if (!row) {
            prioraction = action;
            markDirty();
            return;
        }
        if (action === 'template') {
            openScopeModalForNewSelection(row, select, cmid, prioraction, state, (finalaction) => {
                prioraction = finalaction;
            });
        } else {
            confirmUnmarkTemplate(container, row, select, action, prioraction, cmid, state, (finalaction) => {
                prioraction = finalaction;
                if (finalaction === 'space') {
                    openSpaceModalForNewSelection(row, select, cmid, before, state, (finalspace) => {
                        prioraction = finalspace;
                        applyTemplateVisual(row, finalspace === 'template', cmid, state);
                    });
                    return;
                }
                applySpaceVisual(row, false, cmid, state);
            });
        }
        markDirty();
    });
};

/**
 * Bind every row action select of the review.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 */
const bindActivityActionSelects = (container, state) => {
    const selects = container.querySelectorAll('select[data-region="activity-action"]');
    selects.forEach(select => bindActivityActionSelect(container, select, state));
};

/**
 * Seed one section's behavior from its server-rendered select — the saved
 * behavior in edit mode — then keep it in sync on change.
 *
 * @param {HTMLSelectElement} select The section's behavior select.
 * @param {Object} state The live wizard state from init.js.
 */
const bindSectionBehaviorSelect = (select, state) => {
    const sid = parseInt(select.dataset.sid);
    if (!sid) {
        return;
    }
    state.sectionBehavior[sid] = select.value;
    select.addEventListener('change', () => {
        state.sectionBehavior[sid] = select.value;
        markDirty();
    });
};

/**
 * Bind every section behavior select (custom/keep/exclude) of the review.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 */
const bindSectionBehaviorSelects = (container, state) => {
    const selects = container.querySelectorAll('select[data-region="section-behavior"]');
    selects.forEach(select => bindSectionBehaviorSelect(select, state));
};

/**
 * Bind events on the server-rendered review controls (no DOM injection).
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 */
export const bindServerRenderedControls = (container, state) => {
    bindActivityActionSelects(container, state);
    bindTemplateTagClicks(container, state, markDirty);
    bindSpaceTagClicks(container, state, markDirty);
    bindInstanceInserts(container, state, markDirty);
    bindNameEditing(container, markDirty);
    // Row selection checkboxes (three synced tiers) and the single global
    // bulk action bar — see selection_bulk.js.
    bindSelectionAndBulk(container, state, {applicableAction, applyTemplateVisual: applyRowVisual, markDirty});
    bindSectionBehaviorSelects(container, state);
};
