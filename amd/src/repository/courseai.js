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
 * Repository for Course AI AJAX calls.
 *
 * @module     local_coursegen/repository/courseai
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call} from 'local_coursegen/repository/base';

/**
 * Initialize courseai session.
 *
 * @param {Object} params Parameters
 * @param {string} params.prompt Course description prompt
 * @param {string} params.lang Language code (es, en, etc.), the page default when not changed
 * @param {boolean} params.withimages Include image suggestions
 * @param {number} params.systeminstructionid System instruction ID (optional)
 * @param {boolean} params.withsubsections Organise sections into subsections
 * @returns {Promise<Object>} Response with sessionid, threadid and streamingurl
 */
export const initSession = (params) => call('local_coursegen_start_course_planning', {
    prompt: params.prompt,
    lang: params.lang,
    withimages: params.withimages || false,
    systeminstructionid: params.systeminstructionid || 0,
    withsubsections: params.withsubsections || false,
});

/**
 * Initialise filepicker for courseai syllabus upload.
 *
 * @returns {Promise<Object>} Response with clientid, draftitemid, options, templates
 */
export const initFilepicker = () => call('local_coursegen_courseai_filepicker_init', {});

/**
 * Upload syllabus file for courseai session.
 *
 * @param {number} sessionid Course session ID
 * @param {number} draftitemid Draft file area ID
 * @returns {Promise<Object>} Response with success status and filename
 */
export const uploadSyllabus = (sessionid, draftitemid) => call('local_coursegen_courseai_syllabus_upload', {
    sessionid,
    draftitemid,
});

/**
 * Get resumable snapshot for an existing courseai session.
 *
 * @param {number} recordid Session record ID
 * @returns {Promise<Object>} Session snapshot payload
 */
export const getSessionState = (recordid) => call('local_coursegen_get_course_session_state', {recordid});
