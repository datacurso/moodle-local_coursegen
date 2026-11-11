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
 * The import of a template's configuration: choosing another category empties the course chosen for the previous one.
 *
 * @module     local_coursegen/transfer_import
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Empty the course field when the category field changes.
 *
 * The autocomplete drops a chosen item when its label is clicked, so that is what is done for each chosen course.
 *
 * @param {Document} doc The document that holds the form.
 */
export const init = (doc = document) => {
    const category = doc.getElementById('id_category');
    const course = doc.getElementById('id_courseid');
    if (!category || !course) {
        return;
    }
    category.addEventListener('change', () => {
        const field = course.closest('[data-fieldtype="autocomplete"]') || course.parentElement;
        field.querySelectorAll('[data-active-selection] , [role="option"][data-value]').forEach((chosen) => {
            if (chosen.closest('.form-autocomplete-selection')) {
                chosen.click();
            }
        });
    });
};
