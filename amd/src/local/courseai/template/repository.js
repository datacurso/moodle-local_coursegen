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
import Config from 'core/config';

/**
 * Fetch a template's guided-form structure: locked sections/activities, section
 * limits and the admin-allowed activity catalog.
 *
 * @param {number} templateId
 * @returns {Promise<Object>}
 */
export const getTemplateStructure = (templateId) => fetchMany([{
    methodname: 'local_coursegen_get_template_structure',
    args: {templateid: templateId},
}])[0];

/**
 * Open a generation: exports the template, attaches the syllabus and returns
 * the stream whose consumption actually runs it.
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
 * Answer the review of the generated course that a paused run is waiting on.
 *
 * @param {number} sessionId
 * @param {string} action 'accept' or 'replan_activity'.
 * @param {string[]} targetIds Activity uids to generate again; empty means all of them.
 * @param {string} instruction What to change, in the professor's own words.
 * @returns {Promise<Object>} {status}
 */
export const sendTemplateReviewFeedback = (sessionId, action, targetIds, instruction) => fetchMany([{
    methodname: 'local_coursegen_template_review_feedback',
    args: {
        sessionid: sessionId,
        action: action,
        targetids: targetIds || [],
        instruction: instruction || '',
    },
}])[0];

/**
 * The course settings the generation proposes, for the teacher to review before the course exists.
 *
 * @param {number} sessionId
 * @returns {Promise<Object>} {fullname, shortname, category, categories}
 */
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
export const finishTemplateGeneration = (sessionId, overrides) => fetchMany([{
    methodname: 'local_coursegen_finish_template_generation',
    args: {
        sessionid: sessionId,
        fullname: overrides.fullname || '',
        shortname: overrides.shortname || '',
        category: overrides.category || 0,
    },
}])[0];

/**
 * The places of a template that take a file, with what the teacher already brought to each.
 *
 * @param {number} templateId
 * @returns {Promise<Object>} {hasslots, slots}
 */
export const getTemplateReferenceSlots = (templateId) => fetchMany([{
    methodname: 'local_coursegen_get_template_reference_slots',
    args: {templateid: templateId},
}])[0];

/**
 * Upload the file for one place of a template, or empty the place.
 *
 * A real file cannot travel through a web service call, so this posts a
 * multipart form to the page that takes it.
 *
 * @param {number} templateId
 * @param {string} slotKey
 * @param {string} action 'upload' or 'remove'.
 * @param {File|null} file The file to upload, null to remove.
 * @returns {Promise<Object>} {filename}
 */
export const sendReferenceFile = async(templateId, slotKey, action, file) => {
    const body = new FormData();
    body.append('sesskey', Config.sesskey);
    body.append('templateid', templateId);
    body.append('slotkey', slotKey);
    body.append('action', action);
    if (file) {
        body.append('file', file);
    }
    const response = await fetch(`${Config.wwwroot}/local/coursegen/reference_file.php`, {method: 'POST', body});
    const answer = await response.json();
    if (answer.error) {
        throw new Error(answer.error);
    }
    return answer;
};
