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
 * Repository helpers for course planning actions.
 *
 * @module     local_coursegen/repository/course
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from 'core/ajax';

/**
 * Send human feedback for a planning session.
 *
 * @param {{recordid: number, action: string, instruction: string}} payload
 * @return {Promise<Object>} response
 */
export async function sendPlanningFeedback({recordid, action, instruction}) {
    const args = {
        recordid: Number(recordid) || 0,
        ['approval_status']: action,
        instruction,
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_course_planning_feedback',
            args,
        },
    ])[0];
}

/**
 * Create a course from a stored planning session and apply AI-generated content.
 *
 * @param {{
 *     recordid: number,
 * }} payload - The payload to create the course
 * - recordid: The planning session record ID in local_coursegen_course_sessions
 * @return {Promise<Object>} response
 */
export async function createCourse({recordid}) {
    const args = {
        recordid: Number(recordid) || 0,
    };
    return ajax.call([
        {
            methodname: "local_coursegen_create_course",
            args,
        },
    ])[0];
}

