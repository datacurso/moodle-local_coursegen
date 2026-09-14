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
 * @module     local_coursegen/local/template/selection_bulk
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
 * Bind the single global bulk action bar: disabled while nothing is checked
 * anywhere, applies the chosen action to every checked row across all
 * sections (degrading modify/template to keep for unsupported types), then
 * resets itself back to its placeholder.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} applicableAction (action, modname) => string.
 * @param {Function} syncTemplateRowUi (row, action, cmid, state) => void.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
const bindBulkBar = (container, state, applicableAction, syncTemplateRowUi, markDirty) => {
    const bulk = container.querySelector('select[data-region="bulk-action"]');
    if (!bulk) {
        return;
    }

    const updateBulkAvailability = () => {
        bulk.disabled = !container.querySelector('[data-region="activity-select"]:checked');
    };
    updateBulkAvailability();

    // Row checkbox and aggregate changes all bubble up here; an aggregate's
    // own handler (bound directly on it, in bindSelectionTiers) has already
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
            syncTemplateRowUi(row, applied, cmid, state);
            markDirty();
            box.checked = false;
        });
        // The batch is done: clear both aggregate tiers (table select-all and
        // section-header checkbox, including any indeterminate state) and let
        // the bar fall back to its disabled resting state.
        container.querySelectorAll('[data-region="select-all"], [data-region="section-select-all"]').forEach(all => {
            all.checked = false;
            all.indeterminate = false;
        });
        updateBulkAvailability();
    });
};

/**
 * Bind row selection and the global bulk action bar.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Object} helpers
 * @param {Function} helpers.applicableAction (action, modname) => string.
 * @param {Function} helpers.syncTemplateRowUi (row, action, cmid, state) => void.
 * @param {Function} helpers.markDirty Marks the wizard as having unsaved changes.
 */
export const bindSelectionAndBulk = (container, state, {applicableAction, syncTemplateRowUi, markDirty}) => {
    bindSelectionTiers(container);
    bindBulkBar(container, state, applicableAction, syncTemplateRowUi, markDirty);
};
