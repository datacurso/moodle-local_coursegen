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
 * @param {{courseid: number, sectionnum: (number|null), beforemod: (number|null), prompt: string, generateimages: number}} payload
 * @return {Promise<Object>} response
 */
export async function createModStream({courseid, sectionnum, beforemod, prompt, generateimages}) {
    const args = {
        courseid: Number(courseid) || 0,
        sectionnum: typeof sectionnum === 'number' ? sectionnum : null,
        prompt,
        generateimages: Number(generateimages) || 0,
        beforemod: typeof beforemod === 'number' ? beforemod : null,
    };

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
