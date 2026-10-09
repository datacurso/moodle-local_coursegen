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
 * Calls to the web services of the templates.
 *
 * @module     local_coursegen/repository/template
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from 'core/ajax';

/**
 * Delete a template.
 *
 * @param {number} templateid Template to delete, for example 3.
 * @return {Promise<{deleted: boolean}>}
 */
export function deleteTemplate(templateid) {
    const call = {methodname: 'local_coursegen_delete_template', args: {templateid}};

    return ajax.call([call])[0];
}
