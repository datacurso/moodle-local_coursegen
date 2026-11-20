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
 * The readable names of the pictures of a template run, and the line shown under each name.
 *
 * @module     local_coursegen/local/courseai/template/images_names
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const COMPONENT = 'local_coursegen';

/**
 * The text of a section of the template, by its number, as the structure on the page shows it.
 *
 * @param {number|null} section
 * @returns {string}
 */
const sectionName = (section) => {
    const node = document.querySelector(`[data-section-index="${section}"] .sectionname`);
    if (node && node.textContent.trim()) {
        return node.textContent.trim();
    }
    return '';
};

/**
 * Where a picture of an activity goes: the activity, and the page when it has one.
 *
 * @param {Object} picture
 * @returns {string}
 */
export const activityPlace = (picture) => {
    if (picture.place) {
        return `${picture.activity} · ${picture.place}`;
    }
    return picture.activity || '';
};

/**
 * The readable name of a picture.
 *
 * @param {Object} picture
 * @returns {Promise<string>}
 */
export const nameOf = async(picture) => {
    if (picture.kind === 'course_cover') {
        return getString('courseai_images_name_cover', COMPONENT);
    }
    if (picture.kind === 'section_image' || picture.kind === 'summary_image') {
        let section = sectionName(picture.section);
        if (!section) {
            section = await getString('courseai_images_section_fallback', COMPONENT, picture.section);
        }
        if (picture.kind === 'section_image') {
            return getString('courseai_images_name_section', COMPONENT, section);
        }
        return getString('courseai_images_name_summary', COMPONENT, section);
    }
    if (picture.kind === 'inline' && /infograf/i.test(picture.description || '')) {
        return getString('courseai_images_name_infographic', COMPONENT, activityPlace(picture));
    }
    return getString('courseai_images_name_activity', COMPONENT, activityPlace(picture));
};

/**
 * The line under the name of a picture: what it starts from, or why it failed, or how many wait after it.
 *
 * @param {Object} picture
 * @param {number} waitingAfter
 * @returns {Promise<string>}
 */
export const detailOf = async(picture, waitingAfter) => {
    if (picture.state === 'failed') {
        if (picture.reference) {
            return getString('courseai_images_failed', COMPONENT);
        }
        return getString('courseai_images_failed_new', COMPONENT);
    }
    if (picture.state === 'queued') {
        if (waitingAfter > 0) {
            return getString('courseai_images_waiting_more', COMPONENT, waitingAfter);
        }
        return getString('courseai_images_waiting', COMPONENT);
    }
    if (picture.state === 'start') {
        if (picture.reference) {
            return getString('courseai_images_creating_from', COMPONENT, picture.reference);
        }
        return getString('courseai_images_creating', COMPONENT);
    }
    if (picture.reference) {
        return getString('courseai_images_from', COMPONENT, picture.reference);
    }
    return getString('courseai_images_new', COMPONENT);
};
