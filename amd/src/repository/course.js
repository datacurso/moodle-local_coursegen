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
 *     deleted?: boolean,
 * }} payload
 * @return {Promise<Object>} response
 */
export async function regenerateDetailedItem({recordid, target_type, section_index, activity_index, instruction, deleted}) {
    const args = {
        recordid: Number(recordid) || 0,
        target_type,
        section_index: Number(section_index) || 0,
        instruction: instruction || '',
        deleted: Boolean(deleted),
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
 * Get the final AI-generated course settings (fullname, shortname, category) for review.
 *
 * @param {number} recordid The planning session record ID.
 * @return {Promise<{fullname: string, shortname: string, category: number, categories: Array}>}
 */
export async function getCourseSettings(recordid) {
    return ajax.call([
        {
            methodname: 'local_coursegen_get_course_settings',
            args: {recordid: Number(recordid) || 0},
        },
    ])[0];
}

/**
 * Create a course from a stored planning session and apply AI-generated content.
 *
 * @param {{
 *     recordid: number,
 *     fullname?: string,
 *     shortname?: string,
 *     category?: number,
 * }} payload - The payload to create the course
 * - recordid: The planning session record ID in local_coursegen_course_sessions
 * - fullname: Optional override for course fullname
 * - shortname: Optional override for course shortname
 * - category: Optional override for course category ID
 * @return {Promise<Object>} response
 */
export async function createCourse({recordid, fullname, shortname, category}) {
    const args = {
        recordid: Number(recordid) || 0,
    };
    if (typeof fullname === 'string' && fullname.trim()) {
        args.fullname = fullname.trim();
    }
    if (typeof shortname === 'string' && shortname.trim()) {
        args.shortname = shortname.trim();
    }
    if (typeof category === 'number' && category > 0) {
        args.category = category;
    }
    return ajax.call([
        {
            methodname: "local_coursegen_create_course",
            args,
        },
    ])[0];
}
