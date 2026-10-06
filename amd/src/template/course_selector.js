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
 * Feeds the course picker of the template editor with the courses of the chosen category.
 *
 * @module     local_coursegen/template/course_selector
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {searchCourses} from 'local_coursegen/repository/template';

const CATEGORY_REGION = '[data-region="local_coursegen/template/category"]';

/**
 * The category chosen in the filter, or 0 for all of them.
 *
 * @return {number}
 */
function chosenCategory() {
    const select = document.querySelector(CATEGORY_REGION);
    if (!select) {
        return 0;
    }

    const categoryid = parseInt(select.value, 10);
    if (Number.isNaN(categoryid)) {
        return 0;
    }

    return categoryid;
}

/**
 * Search the courses for the autocomplete.
 *
 * @param {string} selector Selector of the course select.
 * @param {string} query What the admin typed.
 * @param {function} success Receives the courses.
 * @param {function} failure Receives the error.
 */
export async function transport(selector, query, success, failure) {
    const categoryid = chosenCategory();
    try {
        const courses = await searchCourses(categoryid, query);
        success(courses);
    } catch (error) {
        failure(error);
    }
}

/**
 * Turn the courses into the options of the autocomplete.
 *
 * @param {string} selector Selector of the course select.
 * @param {Array<{id: number, fullname: string, shortname: string}>} results The courses found.
 * @return {Array<{value: number, label: string}>}
 */
export function processResults(selector, results) {
    const options = [];
    for (const course of results) {
        options.push({value: course.id, label: course.fullname + ' (' + course.shortname + ')'});
    }

    return options;
}
