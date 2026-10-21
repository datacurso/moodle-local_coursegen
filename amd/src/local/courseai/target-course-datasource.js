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
 * Autocomplete datasource for the "import into an existing course" target field.
 *
 * Implements the core/form-autocomplete ajax contract (transport + processResults).
 * The search is scoped to the category chosen in the sibling #importCategory
 * field, read at request time so both fields stay in sync (ticket #9539).
 *
 * @module     local_coursegen/local/courseai/target-course-datasource
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @type {string} Id of the category select the search is scoped to. */
const CATEGORY_SELECT_ID = 'importCategory';

/** @type {number} Maximum number of courses requested per search. */
const RESULT_LIMIT = 20;

/**
 * Fetch the courses of the selected category matching the query.
 *
 * Without a selected category there is nothing to search: the list is empty.
 *
 * @param {string|HTMLElement} selector The original select (unused: the category is a sibling field).
 * @param {string} query Text typed by the user.
 * @param {Function} success Called with the raw web service results.
 * @param {Function} failure Called with the error (core shows it through Notification).
 * @returns {Promise<void>}
 */
export const transport = async(selector, query, success, failure) => {
    const categorySelect = document.getElementById(CATEGORY_SELECT_ID);
    const categoryid = parseInt(categorySelect?.value || '', 10);
    if (!(categoryid > 0)) {
        success([]);
        return;
    }
    try {
        const results = await Ajax.call([{
            methodname: 'local_coursegen_search_target_courses',
            args: {categoryid, query: String(query || ''), limit: RESULT_LIMIT},
        }])[0];
        success(results);
    } catch (error) {
        failure(error);
    }
};

/**
 * Map web service rows to autocomplete options.
 *
 * @param {string|HTMLElement} selector The original select (unused).
 * @param {Array<{id: number, displayname: string}>} results Rows from local_coursegen_search_target_courses.
 * @returns {Array<{value: number, label: string}>}
 */
export const processResults = (selector, results) => {
    const rows = Array.isArray(results) ? results : [];
    return rows.map((course) => ({value: course.id, label: course.displayname}));
};
