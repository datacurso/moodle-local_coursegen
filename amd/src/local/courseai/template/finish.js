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
 * The end of a template generation: the same review of the course's name and the
 * same completion view a course made without a template ends with.
 *
 * Nothing here is drawn by hand. The review form, its progress card and the
 * completion view are the ones free creation uses, already in the page; this
 * only brings them forward and asks them, through the page's own actions, for
 * what free creation asks of them.
 *
 * @module     local_coursegen/local/courseai/template/finish
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {finishTemplateGeneration, getTemplateCourseSettings} from './repository';

/** Where "create another course" leads when the course came from a template. */
const CREATE_ANOTHER_URL = 'aicoursecreation.php?mode=template';

/**
 * Swap the template's structure for the view the review and the completion live in.
 */
const showFinishView = () => {
    const templateMain = document.getElementById('tplModeMain');
    if (templateMain) {
        templateMain.style.display = 'none';
    }
    const planningView = document.getElementById('planningView');
    if (planningView) {
        planningView.style.display = 'flex';
    }
    const progressCard = document.getElementById('planningProgressCard');
    if (progressCard) {
        progressCard.style.display = '';
    }
};

/**
 * What the completion view says the course holds, from the structure that was generated.
 *
 * @param {Object} tplState
 * @returns {{units: number, activities: number, images: number}}
 */
const completionStats = (tplState) => ({
    units: tplState.sections.length,
    activities: tplState.sections.reduce((sum, section) => sum + section.activities.length, 0),
    images: 0,
});

/**
 * Ask the teacher to review the proposed name, then create the course with it.
 *
 * Resolves with the created course, or null when the teacher backs out of the
 * review, as free creation does.
 *
 * @param {Object} host Holds the page's actions, bound once the page is set up.
 * @param {Object} state Page state.
 * @param {Object} tplState The template form's state.
 * @param {number} sessionId
 * @returns {Promise<Object|null>}
 */
export const reviewAndCreate = async(host, state, tplState, sessionId) => {
    const {actions} = host;
    state.sessionid = sessionId;
    state.completionStats = completionStats(tplState);
    state.withImages = !!tplState.generateimages;
    state.createAnotherUrl = CREATE_ANOTHER_URL;

    showFinishView();
    actions.showReviewState();
    const overrides = await actions.showCourseReviewPanel(getTemplateCourseSettings);
    if (overrides === null) {
        return null;
    }
    return actions.createCourseFromSession(
        overrides,
        (payload) => finishTemplateGeneration(payload.recordid, payload)
    );
};
