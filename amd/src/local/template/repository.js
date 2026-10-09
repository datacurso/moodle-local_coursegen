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
 * Repository for the template editor's AJAX calls.
 *
 * @module     local_coursegen/local/template/repository
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Get the "Course sections" review of a course, rendered with the same server-side code as the first page load.
 *
 * @param {number} courseid Course ID, for example 42.
 * @param {number} templateid Existing template whose saved choices preselect the review (0: none).
 * @returns {Promise<Object>} Resolves with {html, courseid, fullname, shortname}.
 */
export const getCoursePreview = (courseid, templateid = 0) => Ajax.call([{
    methodname: 'local_coursegen_get_course_preview',
    args: {courseid, templateid},
}])[0];

/**
 * Find the courses of a category whose name contains a text.
 *
 * @param {number} categoryid Category ID, for example 3.
 * @param {string} query Text the course name contains, for example "marketing".
 * @returns {Promise<Array>} Resolves with the courses, each {id, fullname, shortname}.
 */
export const searchCourses = (categoryid, query) => Ajax.call([{
    methodname: 'local_coursegen_search_template_courses',
    args: {categoryid, query},
}])[0];

/**
 * Save a template (create or update).
 *
 * @param {Object} data The arguments of the save web service.
 * @param {number} data.templateid Template ID (0 for new).
 * @param {number} data.courseid Course the template is based on.
 * @param {string} data.name Template name.
 * @param {string} data.description Template description.
 * @param {Array} data.items What the AI does with each activity, each {cmid, action, instruction}.
 * @returns {Promise<Object>} Resolves with {templateid, itemcount}.
 */
export const saveTemplate = (data) => Ajax.call([{
    methodname: 'local_coursegen_save_template',
    args: data,
}])[0];
