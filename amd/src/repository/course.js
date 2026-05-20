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
 * @param {{recordid: number, action: string, instruction: string, selectedimageids?: string[]}} payload
 * @return {Promise<Object>} response
 */
export async function sendPlanningFeedback({recordid, action, instruction, selectedimageids, withimages}) {
    const args = {
        recordid: Number(recordid) || 0,
        ['approval_status']: action,
        instruction,
    };

    // Include selected image IDs if provided (can be empty when user deselects all)
    if (Array.isArray(selectedimageids)) {
        args.selected_image_ids = selectedimageids;
    }

    // Include with_images flag when it changes
    if (typeof withimages === 'boolean') {
        args.with_images = withimages;
    }

    return ajax.call([
        {
            methodname: 'local_coursegen_course_planning_feedback',
            args,
        },
    ])[0];
}

/**
 * Regenerate a single section, activity, or image in the detailed plan.
 *
 * @param {{
 *     recordid: number,
 *     target_type: string,
 *     section_index: number,
 *     activity_index?: number,
 *     instruction?: string,
 * }} payload
 * @return {Promise<Object>} response
 */
export async function regenerateDetailedItem({recordid, target_type, section_index, activity_index, instruction}) {
    const args = {
        recordid: Number(recordid) || 0,
        target_type,
        section_index: Number(section_index) || 0,
        instruction: instruction || '',
    };
    if (typeof activity_index === 'number' && activity_index >= 0) {
        args.activity_index = activity_index;
    }
    return ajax.call([
        {
            methodname: 'local_coursegen_regenerate_detailed_item',
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
