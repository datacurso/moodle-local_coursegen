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
 * Build the course, once the stream has reported the generation complete.
 *
 * @param {number} sessionId
 * @returns {Promise<Object>} {status, courseid, courseurl}
 */
export const finishTemplateGeneration = (sessionId) => fetchMany([{
    methodname: 'local_coursegen_finish_template_generation',
    args: {sessionid: sessionId},
}])[0];
