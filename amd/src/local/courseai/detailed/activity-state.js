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
 * Activity state mutation helpers for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/activity-state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove activity entries for a section so they can be recreated on regeneration.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 */
export const clearSectionEntries = (ctx, sectionId) => {
    const {state} = ctx;
    Object.entries(state.detailedActivityEls).forEach(([key, entry]) => {
        if (entry.sectionId === sectionId) {
            if (entry.item && entry.item.parentNode) {
                entry.item.remove();
            }
            delete state.detailedActivityEls[key];
        }
    });
    // Reset section meta so it starts counting from 0.
    if (state.detailedSectionMeta[sectionId]) {
        state.detailedSectionMeta[sectionId].done = 0;
        state.detailedSectionMeta[sectionId].total = 0;
        if (state.detailedSectionMeta[sectionId].metaEl) {
            state.detailedSectionMeta[sectionId].metaEl.textContent = '';
        }
    }
};
