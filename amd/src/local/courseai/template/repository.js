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
 * AJAX calls for the template-mode guided form.
 *
 * @module     local_coursegen/local/courseai/template/repository
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';

/**
 * Fetch a template's guided-form structure: locked sections/activities, section
 * limits and the admin-allowed activity catalog.
 *
 * @param {number} templateId
 * @returns {Promise<Object>}
 */
export const getTemplateStructure = (templateId) => fetchMany([{
    methodname: 'local_coursegen_get_template_structure',
    args: {templateid: templateId},
}])[0];
