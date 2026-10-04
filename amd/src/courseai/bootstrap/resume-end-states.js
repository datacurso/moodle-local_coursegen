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
 * How a reloaded page shows a generation that has already ended.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-end-states
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show the course details form again, the way the live stream does when the generation completes.
 *
 * The form waits for the user, so this does not wait for it: the page keeps loading while the form is open.
 *
 * @param {Object} params
 * @param {Function} params.createCourseFromSession Opens the course details form and creates the course.
 * @param {Function} params.emitLog Adds a turn to the conversation.
 * @param {Object} params.texts Localized strings.
 * @returns {Promise<void>} Resolves when the form is closed or has failed.
 */
export const askForCourseDetails = async({createCourseFromSession, emitLog, texts}) => {
    try {
        await createCourseFromSession();
    } catch (error) {
        const reason = error && error.message;
        emitLog({actor: 'ai', kind: 'danger', message: reason || texts.courseai_error_generic});
    }
};

/**
 * Show that the generation failed, the way the live stream does when it reports a failure.
 *
 * @param {Object} params
 * @param {Object} params.state The page state.
 * @param {Object} params.stepsUi Progress steps of the page.
 * @param {Object} params.detailedUi The plan panel.
 * @param {Function} params.emitLog Adds a turn to the conversation.
 * @param {Object} params.texts Localized strings.
 */
export const showFailure = ({state, stepsUi, detailedUi, emitLog, texts}) => {
    state.currentStage = 'failed';
    stepsUi.setStepState('planning', 'active');
    emitLog({actor: 'ai', kind: 'danger', message: texts.courseai_error_generic || 'Generation failed'});
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
};
