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
 * Repository helpers for activity generation actions.
 *
 * @module     local_coursegen/repository/activity
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from 'core/ajax';

/**
 * Start streaming job to create a module with AI.
 *
 * @param {{courseid: number, sectionnum: (number|null), beforemod: (number|null),
 *     prompt: string, generateimages: number, lang: string, modname: string, h5ptype: string}} payload
 * @return {Promise<Object>} response
 */
export async function createModStream({courseid, sectionnum, beforemod, prompt, generateimages, lang, modname, h5ptype}) {
    const args = {
        courseid: Number(courseid) || 0,
        sectionnum: typeof sectionnum === 'number' ? sectionnum : null,
        prompt,
        generateimages: Number(generateimages) || 0,
        beforemod: typeof beforemod === 'number' ? beforemod : null,
    };

    const language = String(lang || '').toLowerCase();
    if (language) {
        args.lang = language;
    }

    // The type the teacher picked from the list; without it the service decides.
    if (modname) {
        args.modname = String(modname);
    }
    if (h5ptype) {
        args.h5ptype = String(h5ptype);
    }

    return ajax.call([
        {
            methodname: 'local_coursegen_create_mod_stream',
            args,
        },
    ])[0];
}

/**
 * Create the actual activity in Moodle from a finished AI job.
 *
 * @param {{courseid: number, sectionnum: (number|null), jobid: string, beforemod: (number|null)}} payload
 * @return {Promise<Object>} response
 */
export async function createMod({courseid, sectionnum, jobid, beforemod}) {
    const args = {
        courseid: Number(courseid) || 0,
        sectionnum: typeof sectionnum === 'number' ? sectionnum : 0,
        jobid,
        beforemod: typeof beforemod === 'number' ? beforemod : null,
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_create_mod',
            args,
        },
    ])[0];
}

/**
 * Send human feedback for an existing AI activity generation job.
 *
 * @param {{courseid: number, jobid: string, approvalstatus: string, instruction: string}} payload
 * @return {Promise<Object>} response
 */
export async function sendActivityFeedback({courseid, jobid, approvalstatus, instruction}) {
    const args = {
        courseid: Number(courseid) || 0,
        jobid,
        approvalstatus,
        instruction,
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_activity_feedback',
            args,
        },
    ])[0];
}

/**
 * Initialise a filepicker draft area for activity uploads.
 *
 * @param {{courseid: number}} payload
 * @return {Promise<Object>} response
 */
export async function initActivityFilepicker({courseid}) {
    const args = {
        courseid: Number(courseid) || 0,
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_activity_filepicker_init',
            args,
        },
    ])[0];
}

/**
 * Upload a draft file to the AI activity thread.
 *
 * @param {{courseid: number, jobid: string, draftitemid: number}} payload
 * @return {Promise<Object>} response
 */
export async function uploadActivityFile({courseid, jobid, draftitemid}) {
    const args = {
        courseid: Number(courseid) || 0,
        jobid,
        draftitemid: Number(draftitemid) || 0,
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_activity_file_upload',
            args,
        },
    ])[0];
}

/**
 * Upload the picture a teacher chose for one slide of a Course Presentation.
 *
 * @param {{courseid: number, jobid: string, draftitemid: number, slide: number}} payload
 * @return {Promise<Object>} response
 */
export async function uploadActivitySlideImage({courseid, jobid, draftitemid, slide}) {
    return ajax.call([
        {
            methodname: 'local_coursegen_activity_slide_image_upload',
            args: {
                courseid: Number(courseid) || 0,
                jobid,
                draftitemid: Number(draftitemid) || 0,
                slide: Number(slide) || 0,
            },
        },
    ])[0];
}

/**
 * Run an AJAX call of the Course Presentation editor and read the JSON the AI service answered.
 *
 * @param {string} methodname
 * @param {Object} args
 * @return {Promise<Object>} What the AI service answered.
 */
const presentationCall = async(methodname, args) => {
    const response = await ajax.call([{methodname, args}])[0];
    return JSON.parse(response.data);
};

/**
 * Ask the AI to write the slides of a Course Presentation.
 *
 * @param {{courseid: number, jobid: string, instructions: string}} payload
 * @return {Promise<{name: string, intro: string, slides: Object[]}>}
 */
export const presentationDraft = ({courseid, jobid, instructions}) => presentationCall(
    'local_coursegen_activity_presentation_draft',
    {courseid: Number(courseid) || 0, jobid, instructions: instructions || ''}
);

/**
 * Ask the AI to write one slide, or one of its parts, again.
 *
 * @param {{courseid: number, jobid: string, slides: Object[], index: number, instruction: string, part: string}} payload
 * @return {Promise<Object>} The slide written again.
 */
export const presentationSlide = ({courseid, jobid, slides, index, instruction, part}) => presentationCall(
    'local_coursegen_activity_presentation_slide',
    {
        courseid: Number(courseid) || 0,
        jobid,
        slides: JSON.stringify(slides),
        index: Number(index) || 0,
        instruction,
        part: part || '',
    }
);

/**
 * Ask the AI service to make the package from the slides as they were left.
 *
 * @param {{courseid: number, jobid: string, name: string, intro: string, slides: Object[]}} payload
 * @return {Promise<Object>}
 */
export const presentationBuild = ({courseid, jobid, name, intro, slides}) => presentationCall(
    'local_coursegen_activity_presentation_build',
    {courseid: Number(courseid) || 0, jobid, name, intro: intro || '', slides: JSON.stringify(slides)}
);

/**
 * The unfinished work of the person in the activity editor.
 *
 * @param {{courseid: number, kind: string}} payload
 * @return {Promise<{exists: boolean, data: string, timemodified: number}>}
 */
export const draftResume = ({courseid, kind}) => ajax.call([{
    methodname: 'local_coursegen_activity_draft_resume',
    args: {courseid: Number(courseid) || 0, kind},
}])[0];

/**
 * Save the work of the person in the activity editor.
 *
 * @param {{courseid: number, kind: string, data: string}} payload
 * @return {Promise<{saved: boolean}>}
 */
export const draftUpdate = ({courseid, kind, data}) => ajax.call([{
    methodname: 'local_coursegen_activity_draft_update',
    args: {courseid: Number(courseid) || 0, kind, data},
}])[0];

/**
 * Discard the saved work of the person in the activity editor.
 *
 * @param {{courseid: number, kind: string}} payload
 * @return {Promise<Object>}
 */
export const draftReset = ({courseid, kind}) => ajax.call([{
    methodname: 'local_coursegen_activity_draft_reset',
    args: {courseid: Number(courseid) || 0, kind},
}])[0];
