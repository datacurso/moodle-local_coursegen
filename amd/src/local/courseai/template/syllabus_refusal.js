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
 * What the start form does when the service refuses the syllabus it was given.
 *
 * The server empties the draft area of the syllabus as soon as it has read it, whether the service accepted it or
 * not, so a refused syllabus cannot be sent again: the form forgets it and the teacher attaches another one.
 *
 * @module     local_coursegen/local/courseai/template/syllabus_refusal
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const REFUSAL_PREFIX = 'templatesyllabus';

/**
 * Whether an error of the start service is a message about the syllabus.
 *
 * @param {*} error What the web service call rejected with, for example {errorcode: 'templatesyllabusrejected'}.
 * @returns {boolean} True for the messages about the syllabus.
 */
export const isSyllabusRefusal = (error) => {
    if (!error || typeof error !== 'object') {
        return false;
    }
    const code = String(error.errorcode || '');
    return code.startsWith(REFUSAL_PREFIX);
};

/**
 * Forget the syllabus of the form when the service refused it.
 *
 * @param {Object} tplState State of the template form, with syllabusdraftitemid and syllabusfilename.
 * @param {*} error What the web service call rejected with.
 * @returns {boolean} True when the syllabus was forgotten.
 */
export const forgetRefusedSyllabus = (tplState, error) => {
    if (!isSyllabusRefusal(error)) {
        return false;
    }
    tplState.syllabusdraftitemid = 0;
    tplState.syllabusfilename = '';
    return true;
};
