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
 * - Per-row selection checkboxes (data-region="activity-select") plus TWO
 *   per-section aggregates kept in sync with them: the table-header
 *   select-all (data-region="select-all") and the card-header select-all
 *   (data-region="section-select-all", usable while the section is
 *   collapsed). All ephemeral UI state — never persisted, reset by every
 *   re-render.
 * - The single global bulk select (data-region="bulk-action") below all the
 *   cards, mirroring the users-table "With selected users…" pattern: born
 *   disabled and kept disabled while no activity checkbox is checked
 *   anywhere; on change it applies the chosen action to every checked row
 *   across ALL sections (degrading modify to keep for types the generator
 *   does not support), then resets itself back to its placeholder.
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
 * produce content for degrade modify to keep (their selects do not even
 * offer a modify option — same rule as the server-side defaults).
 *
 * @param {string} action Requested action.
 * @param {string} modname The row's module type.
 * @returns {string} The action to apply.
 */
const applicableAction = (action, modname) =>
    (action === 'modify' && !typeSupportsModify(modname)) ? 'keep' : action;

/**
 * Bind events on the server-rendered review controls (no DOM injection).
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 */
export const bindServerRenderedControls = (container, state) => {
    // Row action selects: seed state from the server-rendered default, then
    // track every change.
    container.querySelectorAll('select[data-region="activity-action"]').forEach(select => {
        const cmid = parseInt(select.dataset.id);
        if (!cmid) {
            return;
        }
        state.activityAction[cmid] = select.value;
        select.addEventListener('change', () => {
            state.activityAction[cmid] = select.value;
            markDirty();
        });
    });

    // Three selection tiers per section, kept in sync: the card-header
    // select-all, the table-header select-all and the row checkboxes.
    // Toggling either aggregate sets every row of its own section; a row
    // change recomputes both aggregates — fully checked when every row is,
    // indeterminate (the mixed visual state) when only some are. Clicking
    // an indeterminate aggregate checks it, i.e. selects the whole section.
    container.querySelectorAll('[data-for="section"]').forEach(card => {
        const aggregates = [
            card.querySelector('[data-region="section-select-all"]'),
            card.querySelector('[data-region="select-all"]'),
        ].filter(box => box !== null);
        if (!aggregates.length) {
            return;
        }
        const rowboxes = () => [...card.querySelectorAll('[data-region="activity-select"]')];
        const syncAggregates = () => {
            const boxes = rowboxes();
            const checked = boxes.filter(box => box.checked).length;
            aggregates.forEach(aggregate => {
                aggregate.checked = checked > 0 && checked === boxes.length;
                aggregate.indeterminate = checked > 0 && checked < boxes.length;
            });
        };
        aggregates.forEach(aggregate => {
            aggregate.addEventListener('change', () => {
                rowboxes().forEach(box => {
                    box.checked = aggregate.checked;
                });
                syncAggregates();
            });
        });
        card.addEventListener('change', (e) => {
            if (e.target.matches('[data-region="activity-select"]')) {
                syncAggregates();
            }
        });
    });

    // Global bulk action bar: disabled while nothing is checked anywhere;
    // applies to every checked row across all sections, then resets back to
    // its placeholder.
    const bulk = container.querySelector('select[data-region="bulk-action"]');
    if (bulk) {
        const updateBulkAvailability = () => {
            bulk.disabled = !container.querySelector('[data-region="activity-select"]:checked');
        };
        updateBulkAvailability();
        // Row checkbox and aggregate changes all bubble up here; an
        // aggregate's own handler (bound directly on it, above) has already
        // toggled its rows by the time this delegated one runs.
        const selectionregions = '[data-region="activity-select"], [data-region="select-all"],'
            + ' [data-region="section-select-all"]';
        container.addEventListener('change', (e) => {
            if (e.target.matches(selectionregions)) {
                updateBulkAvailability();
            }
        });
        bulk.addEventListener('change', () => {
            const action = bulk.value;
            bulk.value = '';
            if (!action) {
                return;
            }
            container.querySelectorAll('[data-region="activity-select"]:checked').forEach(box => {
                const row = box.closest('[data-for="cmitem"]');
                const cmid = row ? parseInt(row.dataset.id) : 0;
                if (!cmid) {
                    return;
                }
                const applied = applicableAction(action, row.dataset.modname);
                const select = row.querySelector('select[data-region="activity-action"]');
                if (select) {
                    select.value = applied;
                }
                state.activityAction[cmid] = applied;
                markDirty();
                box.checked = false;
            });
            // The batch is done: clear both aggregate tiers (table select-all
            // and section-header checkbox, including any indeterminate state)
            // and let the bar fall back to its disabled resting state.
            container.querySelectorAll('[data-region="select-all"], [data-region="section-select-all"]').forEach(all => {
                all.checked = false;
                all.indeterminate = false;
            });
            updateBulkAvailability();
        });
    }

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
