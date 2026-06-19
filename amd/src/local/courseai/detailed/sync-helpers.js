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
 * Incremental rendering helpers for syncDetailedStructureFromSections.
 *
 * @module     local_coursegen/local/courseai/detailed/sync-helpers
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createDetailedSectionRow} from './section-row';
import {createDetailedActivityRow} from './activity-row';
import {wireDragAndDrop, sendReorderSections} from './dnd';

/**
 * Render a single section row if it has not been created yet.
 * Returns the section meta (existing or newly created).
 *
 * @param {Object} ctx
 * @param {Object} section      - Normalized section object.
 * @param {number} renderIndex
 * @returns {Object|null}
 */
export const ensureSectionRendered = (ctx, section, renderIndex) => {
    const {state} = ctx;
    if (state.detailedSectionMeta[section.id]) {
        return state.detailedSectionMeta[section.id];
    }
    createDetailedSectionRow(ctx, {
        sectionId: section.id,
        sectionIndex: renderIndex,
        renderIndex,
        sectionName: section.name,
        totalActivities: (section.activities || []).length,
    });
    const meta = state.detailedSectionMeta[section.id];
    if (!meta) {
        return null;
    }
    // Keep the body open so streaming activity rows are visible immediately.
    meta.bodyEl.style.display = 'flex';
    // Wire the new section row into section-level drag-and-drop.
    // First section: create the wirer; subsequent sections: attach to its API.
    if (!state.sectionDnd) {
        state.sectionDnd = wireDragAndDrop(
            ctx.elements.prvSections,
            '.prv-section-row',
            'sectionId',
            (ids) => sendReorderSections(ctx, ids),
            null
        );
    } else {
        state.sectionDnd.attachToRow(meta.row);
    }
    return meta;
};

/**
 * Render a single activity row inside its section if it has not been created yet.
 *
 * @param {Object}      ctx
 * @param {Object}      activity      - Normalized activity object.
 * @param {string}      sectionId
 * @param {number}      activityIndex
 * @param {HTMLElement} bodyEl
 */
export const ensureActivityRendered = (ctx, activity, sectionId, activityIndex, bodyEl) => {
    const {state} = ctx;
    if (state.detailedActivityEls[activity.id]) {
        return;
    }
    createDetailedActivityRow(ctx, {
        sectionId,
        activityId: activity.id,
        sectionIndex: activityIndex,
        activityIndex,
        activityType: activity.activity_type,
        activityTitle: activity.title,
        bodyEl,
    });
};
