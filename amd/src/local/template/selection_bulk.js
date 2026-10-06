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
 * Row-selection checkboxes and the single global bulk action bar, for the
 * server-rendered "Course sections" review. Split out of sections_events.js
 * so that module stays focused on seeding/tracking each control's own state.
 *
 * The bulk bar offers the same two actions as every row: keep intact or
 * modify with AI. Applying one leaves each checked row exactly as if its own
 * select had been changed.
 *
 * @module     local_coursegen/local/template/selection_bulk
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {applyRowAction} from 'local_coursegen/local/template/row_action';
import Selectors from 'local_coursegen/local/template/selectors';
import {EVENT} from 'local_coursegen/local/template/constants';

/** @type {string} Every control whose change can enable or disable the bulk bar. */
const SELECTION_REGIONS = [
    Selectors.regions.activitySelect,
    Selectors.regions.selectAll,
    Selectors.regions.sectionSelectAll,
].join(', ');

/**
 * Bind the three selection tiers per section (card-header select-all,
 * table-header select-all, row checkboxes) so toggling any aggregate sets
 * every row of its own section, and a row change recomputes both aggregates.
 *
 * @param {HTMLElement} container The rendered course sections review.
 */
const bindSelectionTiers = (container) => {
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
};

/**
 * Read every currently-checked row across all sections.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @returns {HTMLElement[]} The checked rows (data-for="cmitem").
 */
const collectCheckedRows = (container) => [...container.querySelectorAll('[data-region="activity-select"]:checked')]
    .map(box => box.closest('[data-for="cmitem"]'))
    .filter(row => row !== null);

/**
 * Enable the bulk select only while some row is checked.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {HTMLSelectElement} bulk The single global bulk select.
 */
const updateBulkAvailability = (container, bulk) => {
    const checked = container.querySelector(Selectors.regions.activitySelectChecked);
    bulk.disabled = !checked;
};

/**
 * Apply one action to every given row.
 *
 * @param {HTMLElement[]} rows The checked rows (data-for="cmitem").
 * @param {string} action The requested action.
 * @param {Object} state The live editor state from init.js.
 */
const applyActionToRows = (rows, action, state) => {
    for (const row of rows) {
        applyRowAction(row, action, state);
    }
};

/**
 * Uncheck the selection checkbox of every given row.
 *
 * @param {HTMLElement[]} rows The rows (data-for="cmitem").
 */
const clearRowSelection = (rows) => {
    for (const row of rows) {
        const box = row.querySelector(Selectors.regions.activitySelect);
        if (box) {
            box.checked = false;
        }
    }
};

/**
 * Clear both aggregate tiers (table select-all and section-header checkbox),
 * including any indeterminate state.
 *
 * @param {HTMLElement} container The rendered course sections review.
 */
const clearAggregates = (container) => {
    const selector = `${Selectors.regions.selectAll}, ${Selectors.regions.sectionSelectAll}`;
    const aggregates = container.querySelectorAll(selector);
    for (const aggregate of aggregates) {
        aggregate.checked = false;
        aggregate.indeterminate = false;
    }
};

/**
 * Apply the chosen bulk action to every checked row, then fall back to the
 * placeholder and the disabled resting state.
 *
 * @param {Object} ctx The bar's binding context {container, bulk, state, markDirty}.
 */
const handleBulkChange = (ctx) => {
    const action = ctx.bulk.value;
    ctx.bulk.value = '';
    if (!action) {
        return;
    }
    const rows = collectCheckedRows(ctx.container);
    applyActionToRows(rows, action, ctx.state);
    ctx.markDirty();
    clearRowSelection(rows);
    clearAggregates(ctx.container);
    updateBulkAvailability(ctx.container, ctx.bulk);
};

/**
 * Row checkbox and aggregate changes all bubble up to the container; an
 * aggregate's own handler (bound directly on it, in bindSelectionTiers) has
 * already toggled its rows by the time this delegated one runs.
 *
 * @param {Object} ctx The bar's binding context {container, bulk}.
 * @param {Event} e The change event.
 */
const handleSelectionChange = (ctx, e) => {
    if (e.target.matches(SELECTION_REGIONS)) {
        updateBulkAvailability(ctx.container, ctx.bulk);
    }
};

/**
 * Bind the single global bulk action bar: disabled while nothing is checked
 * anywhere, applies the chosen action to every checked row across all
 * sections, then resets itself back to its placeholder.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live editor state from init.js.
 * @param {Function} markDirty Marks the editor as having unsaved changes.
 */
const bindBulkBar = (container, state, markDirty) => {
    const bulk = container.querySelector(Selectors.regions.bulkAction);
    if (!bulk) {
        return;
    }
    updateBulkAvailability(container, bulk);
    const ctx = {container, bulk, state, markDirty};
    const onSelectionChange = handleSelectionChange.bind(null, ctx);
    container.addEventListener(EVENT.CHANGE, onSelectionChange);
    const onBulkChange = handleBulkChange.bind(null, ctx);
    bulk.addEventListener(EVENT.CHANGE, onBulkChange);
};

/**
 * Bind row selection and the global bulk action bar.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live editor state from init.js.
 * @param {Object} helpers
 * @param {Function} helpers.markDirty Marks the editor as having unsaved changes.
 */
export const bindSelectionAndBulk = (container, state, {markDirty}) => {
    bindSelectionTiers(container);
    bindBulkBar(container, state, markDirty);
};
