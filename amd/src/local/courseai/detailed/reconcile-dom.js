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
 * DOM mutation helpers for the diff-based plan reconciler.
 *
 * Handles fade-removal of vanished entries and in-order reordering of DOM
 * nodes using insertBefore only for nodes that are out of place.
 *
 * @module     local_coursegen/local/courseai/detailed/reconcile-dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {focusChange, markRemoving} from 'local_coursegen/local/courseai/ui/highlight';
import {getSectionList} from './container';

// ---------------------------------------------------------------------------
// Remove vanished entries
// ---------------------------------------------------------------------------

/**
 * Fade-remove all rendered activities whose ids are absent from activeActivityIds.
 * Mutates state.detailedActivityEls.
 *
 * @param {Object}   ctx
 * @param {Set<string>} activeActivityIds
 * @returns {Promise<void>}
 */
export const removeVanishedActivities = async(ctx, activeActivityIds) => {
    const {state} = ctx;
    const toRemove = Object.keys(state.detailedActivityEls).filter((id) => !activeActivityIds.has(id));

    await Promise.all(toRemove.map(async(id) => {
        const entry = state.detailedActivityEls[id];
        if (!entry || !entry.wrap) {
            return;
        }
        await markRemoving(entry.wrap);
        entry.wrap.remove();
        delete state.detailedActivityEls[id];
    }));
};

/**
 * Fade-remove all rendered sections whose ids are absent from activeSectionIds.
 * Mutates state.detailedSectionMeta.
 *
 * @param {Object}   ctx
 * @param {Set<string>} activeSectionIds
 * @returns {Promise<void>}
 */
export const removeVanishedSections = async(ctx, activeSectionIds) => {
    const {state} = ctx;
    const toRemove = Object.keys(state.detailedSectionMeta).filter((id) => !activeSectionIds.has(id));

    await Promise.all(toRemove.map(async(id) => {
        const meta = state.detailedSectionMeta[id];
        if (!meta || !meta.row) {
            return;
        }
        await markRemoving(meta.row);
        meta.row.remove();
        delete state.detailedSectionMeta[id];
    }));
};

// ---------------------------------------------------------------------------
// Reorder nodes
// ---------------------------------------------------------------------------

/**
 * Reorder child nodes of a container to match the ordered list of elements.
 * Uses insertBefore only for nodes that are out of position. Flashes info on
 * each node that actually moved. The sentinel (add-control) stays at the tail.
 *
 * @param {HTMLElement}      container
 * @param {Array<HTMLElement>} orderedNodes  Desired order (without sentinel).
 * @param {HTMLElement|null}   sentinel      Node to keep at the end (or null).
 */
const reorderNodes = (container, orderedNodes, sentinel) => {
    orderedNodes.forEach((node, i) => {
        const expectedPrev = i === 0 ? null : orderedNodes[i - 1];
        const actualPrev = node.previousElementSibling;

        const prevMatches = actualPrev === expectedPrev
            || (expectedPrev === null && (actualPrev === null || actualPrev === sentinel));

        if (!prevMatches) {
            const insertRef = expectedPrev ? expectedPrev.nextSibling : container.firstChild;
            container.insertBefore(node, insertRef);
            focusChange(node, 'info');
        }
    });

    if (sentinel && container.lastElementChild !== sentinel) {
        container.appendChild(sentinel);
    }
};

/**
 * Reorder all section rows and, within each section, all activity rows.
 *
 * @param {Object} ctx
 * @param {Array}  activeSections  Ordered active section descriptors.
 */
export const reorderAll = (ctx, activeSections) => {
    const {state} = ctx;
    const sectionList = getSectionList(ctx);

    const sectionNodes = activeSections
        .map((s) => state.detailedSectionMeta[s.id])
        .filter(Boolean)
        .map((meta) => meta.row);

    const addSectionWrap = sectionList ? sectionList.querySelector('.dp-add-section-wrap') : null;
    if (sectionList) {
        reorderNodes(sectionList, sectionNodes, addSectionWrap);
    }

    activeSections.forEach((section) => {
        const meta = state.detailedSectionMeta[section.id];
        if (!meta || !meta.bodyEl) {
            return;
        }
        const activityNodes = section.activities
            .map((a) => state.detailedActivityEls[a.id])
            .filter(Boolean)
            .map((entry) => entry.wrap);

        const addActivityWrap = meta.bodyEl.querySelector('.dp-add-activity-wrap');
        reorderNodes(meta.bodyEl, activityNodes, addActivityWrap);
    });
};
