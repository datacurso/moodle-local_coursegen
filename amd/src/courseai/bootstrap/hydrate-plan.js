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
 * Detailed-plan hydration helper for the Course AI entrypoint.
 *
 * @module     local_coursegen/courseai/bootstrap/hydrate-plan
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the hydrateDetailedPlanFromSnapshot function bound to a detailedUi instance.
 *
 * @param {Object} detailedUi
 * @returns {Function}
 */
export const makeHydratePlan = (detailedUi) => {
    /**
     * Hydrate the detailed plan UI from a snapshot's section array.
     *
     * @param {Array} sections
     * @returns {void}
     */
    return (sections) => {
        detailedUi.initDetailedPlanView({sections});
        sections.forEach((section, sectionIndex) => {
            (section.activities || []).forEach((activity, activityIndex) => {
                detailedUi.handleDetailedPlanActivity({
                    section_index: section.section_index ?? sectionIndex,
                    activity_index: activityIndex,
                    title: activity.title,
                    activity_type: activity.activity_type,
                    data: activity.detailed_plan || {},
                });
            });
        });
    };
};
