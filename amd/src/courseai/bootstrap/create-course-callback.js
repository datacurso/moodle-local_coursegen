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
 * Factory for the createCourseFromSession stream callback.
 *
 * @module     local_coursegen/courseai/bootstrap/create-course-callback
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {showReviewState} from 'local_coursegen/local/courseai/actions/review-state';

/**
 * Build the async createCourseFromSession callback passed to the stream manager.
 *
 * @param {Object} params
 * @param {Object} params.elements
 * @param {Object} params.stepsUi
 * @param {Object} params.texts
 * @param {Function} params.getActions - Returns the current actions object (late-bound)
 * @returns {Function} async createCourseFromSession callback
 */
export const makeCreateCourseCallback = ({elements, stepsUi, texts, getActions}) => {
    /**
     * Callback invoked by the stream manager when course generation is complete.
     *
     * @returns {Promise<void>}
     */
    return async() => {
        const actions = getActions();
        if (actions) {
            showReviewState(elements, stepsUi, texts);
            // Show the course review panel before creating.
            const overrides = await actions.showCourseReviewPanel();
            if (overrides === null) {
                return;
            }
            await actions.createCourseFromSession(overrides);
        }
    };
};
