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
 * AJAX calls for the template-mode guided form.
 *
 * @module     local_coursegen/local/courseai/template/repository
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Notification from 'core/notification';

/**
 * Fetch a template's guided-form structure: its sections and activities, the
 * spaces for the teacher's files included, and the section limits.
 *
 * @param {number} templateId
 * @returns {Promise<Object>}
 */
export const getTemplateStructure = (templateId) => fetchMany([{
    methodname: 'local_coursegen_get_template_structure',
    args: {templateid: templateId},
}])[0];

/**
 * Open a generation: exports the template, attaches the syllabus and the files
 * of the spaces, and returns the stream whose consumption actually runs it.
 *
 * @param {number} templateId
 * @param {string} prompt The professor's single general instruction.
 * @param {number} draftItemId Draft area holding the syllabus, 0 when none.
 * @returns {Promise<Object>} {threadid, sessionid, streamurl}
 */
export const startTemplateGeneration = (templateId, prompt, draftItemId) => fetchMany([{
    methodname: 'local_coursegen_start_template_generation',
    args: {templateid: templateId, prompt: prompt, draftitemid: draftItemId},
}])[0];

/**
 * Answer the question the template run is paused on.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @param {string} callId Call id of the question, for example "c4".
 * @param {string} kind Kind of answer: "file", "text" or "choice".
 * @param {{draftItemId: number, text: string, choice: string}} answer What the teacher answered.
 * @returns {Promise<Object>} The status of the answer.
 */
export const answerTemplateQuestion = (sessionId, callId, kind, answer) => fetchMany([{
    methodname: 'local_coursegen_answer_template_question',
    args: {
        sessionid: sessionId,
        callid: callId,
        kind: kind,
        draftitemid: answer.draftItemId || 0,
        text: answer.text || '',
        choice: answer.choice || '',
    },
}])[0];

/**
 * Ask a completed run for changes: the run continues from its draft and completes again.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @param {string} callId Id of this request, so sending it twice changes nothing twice, for example "adj0k3j9x2a".
 * @param {string} instruction What to change, in the teacher's own words.
 * @param {string} aid Draft id of the only activity to change, for example "t:11342", or an empty text for all.
 * @returns {Promise<Object>} {status}
 */
export const sendTemplateReviewFeedback = (sessionId, callId, instruction, aid) => fetchMany([{
    methodname: 'local_coursegen_template_review_feedback',
    args: {sessionid: sessionId, callid: callId, instruction: instruction, aid: aid || ''},
}])[0];

/**
 * Tell the service the course will not be built, so it deletes the files it holds for the run.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<Object>} {status}
 */
export const cancelTemplateGeneration = (sessionId) => fetchMany([{
    methodname: 'local_coursegen_cancel_template_generation',
    args: {sessionid: sessionId},
}])[0];

/**
 * Read the state of the run of a session, to repaint a reloaded page.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<Object>} status, threadid, streamurl, pendingquestion and progressevents.
 */
export const getTemplateAgentState = (sessionId) => fetchMany([{
    methodname: 'local_coursegen_get_template_agent_state',
    args: {sessionid: sessionId},
}])[0];

export const getTemplateCourseSettings = (sessionId) => fetchMany([{
    methodname: 'local_coursegen_get_template_course_settings',
    args: {recordid: sessionId},
}])[0];

/**
 * Build the course, once the stream has reported the generation complete and the
 * teacher has reviewed its name.
 *
 * @param {number} sessionId
 * @param {Object} overrides What the teacher chose at the review: {fullname, shortname, category}, each optional.
 * @returns {Promise<Object>} {success, courseid, fullname, shortname, message, courseurl}
 */
export const finishTemplateGeneration = async(sessionId, overrides) => {
    const response = await fetchMany([{
        methodname: 'local_coursegen_finish_template_generation',
        args: {
            sessionid: sessionId,
            fullname: overrides.fullname || '',
            shortname: overrides.shortname || '',
            category: overrides.category || 0,
        },
    }])[0];
    if (response && response.warnings) {
        Notification.addNotification({message: response.warnings, type: 'warning'});
    }
    return response;
};
