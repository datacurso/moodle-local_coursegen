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
 * Completion summary helpers for the course-AI actions.
 *
 * @module     local_coursegen/local/courseai/actions/summary
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return unit/activity/image counts from state.
 *
 * Uses pre-computed completionStats when available so the counts are stable
 * after the user has already accepted the plan.
 *
 * @param {Object} state
 * @returns {{units: number, activities: number, images: number}}
 */
export const getSummaryCounts = (state) => {
    if (state.completionStats) {
        return state.completionStats;
    }

    const units = state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0;
    const activities = state.totalActivities || state.detailedTotal || 0;
    const images = Object.keys(state.selectedDetailedImages || {})
        .filter((id) => state.selectedDetailedImages[id] !== false).length;

    return {units, activities, images};
};

/**
 * Build the localised completion-summary string shown on the completion card.
 *
 * @param {Object}   state
 * @param {Object}   texts
 * @param {Function} formatTemplate
 * @returns {string}
 */
export const buildCompletionSummary = (state, texts, formatTemplate) => {
    const {units, activities, images} = getSummaryCounts(state);
    if (state.withImages) {
        return formatTemplate(texts.courseai_completion_summary_with_images, {
            units,
            activities,
            images,
        });
    }

    return formatTemplate(texts.courseai_completion_summary_no_images, {
        units,
        activities,
    });
};
