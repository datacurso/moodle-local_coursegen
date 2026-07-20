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
 * Repository for template wizard AJAX calls.
 *
 * @module     local_coursegen/local/template/repository
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Get all templates.
 *
 * @returns {Promise<Array>} Resolves with the list of template objects.
 */
export const getTemplates = () => Ajax.call([{
    methodname: 'local_coursegen_get_templates',
    args: {},
}])[0];

/**
 * Delete a template by ID.
 *
 * @param {number} id Template ID to delete.
 * @returns {Promise<Object>} Resolves with the deletion result.
 */
export const deleteTemplate = (id) => Ajax.call([{
    methodname: 'local_coursegen_delete_template',
    args: {id},
}])[0];

/**
 * Get the section/activity structure for a course.
 *
 * @param {number} courseid Moodle course ID.
 * @returns {Promise<Array>} Resolves with an array of section objects.
 */
export const getCourseStructure = (courseid) => Ajax.call([{
    methodname: 'local_coursegen_get_course_structure',
    args: {courseid},
}])[0];

/**
 * Save a template (create or update).
 *
 * @param {Object} data Template payload.
 * @param {number} data.id Template ID (0 for new).
 * @param {string} data.name Template name.
 * @param {string} data.description Template description.
 * @param {number} data.courseid Source course ID.
 * @param {number} data.maxsections Maximum sections allowed.
 * @param {boolean} data.nolimit Whether section limit is disabled.
 * @param {string} data.allowedtypes JSON-encoded array of allowed activity types.
 * @param {string} data.namingpattern Section naming pattern string.
 * @param {number} data.namingstart Starting number for section naming.
 * @param {Array} data.sections Section configuration array.
 * @returns {Promise<Object>} Resolves with the saved template object.
 */
export const saveTemplate = (data) => Ajax.call([{
    methodname: 'local_coursegen_save_template',
    args: data,
}])[0];
