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
     * Hydrate the detailed plan UI from a snapshot's RAW plan sections.
     *
     * Uses the same diff reconciler as the live review (`reconcilePlan`) instead
     * of replaying index-keyed activity events: the live path keys by section/
     * activity UUID, so the index-based replay never attached the activities
     * (sections rendered as empty "0/N" shells on reload). The sections passed
     * here must be the raw plan (with `id` and nested `detailed_plan`), not the
     * index-stripped UI shape.
     *
     * @param {Array} sections - raw plan sections (with ids + detailed_plan)
     * @returns {Promise<void>}
     */
    return async(sections) => {
        // Prepare the detailed view container + reset the diff maps so the
        // reconciler renders everything fresh (no animations on an empty map).
        detailedUi.initDetailedPlanView({renderSections: false});
        await detailedUi.reconcilePlan(sections);
    };
};
