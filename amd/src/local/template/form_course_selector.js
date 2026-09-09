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
 * AJAX transport for the "course" autocomplete field of the base-course
 * picker (see classes/form/course_picker_form.php) — scoped to whichever
 * category is currently selected in the sibling "category" autocomplete.
 *
 * Mirrors core/form-course-selector.js in shape, but core's course search
 * (core_course_search_courses) has no category filter, only free text
 * across every course on the site — this plugin's own
 * local_coursegen_get_courses_by_category already exists for exactly this
 * (category-scoped course listing) and only needed a $query parameter
 * added to also filter by free text.
 *
 * @module     local_coursegen/local/template/form_course_selector
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    return {
        /**
         * @param {string} selector
         * @param {Object} data {courses: [{id, fullname, shortname}, ...]}
         * @returns {Array}
         */
        processResults: function(selector, data) {
            return data.map(function(course) {
                return {value: course.id, label: course.fullname + ' (' + course.shortname + ')'};
            });
        },

        /**
         * @param {string} selector The course autocomplete's own selector.
         * @param {string} query Free text typed so far.
         * @param {Function} success
         * @param {Function} failure
         */
        transport: function(selector, query, success, failure) {
            const categoryField = document.getElementById('id_category');
            const categoryid = categoryField ? parseInt(categoryField.value, 10) : 0;

            if (!categoryid) {
                // No category chosen yet — nothing to search within.
                success([]);
                return;
            }

            Ajax.call([{
                methodname: 'local_coursegen_get_courses_by_category',
                args: {categoryid: categoryid, recursive: true, query: query || ''},
            }])[0].then(success).catch(failure);
        }
    };
});
