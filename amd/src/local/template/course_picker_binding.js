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
 * The base-course picker's own two controls: the "selected course" banner
 * and the category/course autocomplete pair rendered by
 * classes/form/course_picker_form.php. Split out of init.js so that module
 * stays focused on state/region orchestration.
 *
 * @module     local_coursegen/local/template/course_picker_binding
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Reflect the currently selected course in the "selected course" banner.
 *
 * @param {HTMLElement} root The wizard root element.
 * @param {Object} state The live wizard state from init.js.
 */
export const updateSelectedBanner = (root, state) => {
    const banner = root.querySelector('[data-region="selected-banner"]');
    if (!banner) {
        return;
    }
    banner.classList.toggle('d-none', !state.selectedCourseId);
    if (!state.selectedCourseId) {
        return;
    }
    const nameEl = banner.querySelector('[data-region="selected-name"]');
    const shortEl = banner.querySelector('[data-region="selected-short"]');
    const linkEl = banner.querySelector('[data-region="selected-link"]');
    if (nameEl) {
        nameEl.textContent = state.selectedCourse?.fullname || '';
    }
    if (shortEl) {
        shortEl.textContent = state.selectedCourse?.shortname || '';
    }
    if (linkEl) {
        linkEl.href = M.cfg.wwwroot + '/course/view.php?id=' + state.selectedCourseId;
    }
};

/**
 * Bind the category/course autocomplete pair. Neither field is ever
 * submitted — their standard Moodle IDs (id_category, id_courseid) are just
 * read directly, the same way template name/description are read elsewhere.
 *
 * @param {HTMLElement} panel The step-1 panel containing the rendered form.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} setState init.js's own state setter.
 */
export const bindCoursePicker = (panel, state, setState) => {
    const categoryField = panel.querySelector('#id_category');
    const courseField = panel.querySelector('#id_courseid');
    if (!categoryField || !courseField) {
        return;
    }

    // Picking a different category invalidates whatever course was chosen
    // for the previous one — the course autocomplete's own AJAX transport
    // re-scopes to the new category on the next keystroke, but a
    // previously chosen course from the old category must not linger.
    categoryField.addEventListener('change', () => {
        if (state.selectedCourseId) {
            setState({selectedCourseId: null, selectedCourse: null, courseStructure: null});
        }
    });

    courseField.addEventListener('change', () => {
        const id = parseInt(courseField.value, 10);
        if (!id) {
            return;
        }
        const label = courseField.options[courseField.selectedIndex]?.text || '';
        // Label is "Fullname (Shortname)" (see form_course_selector.js);
        // split it back out so the banner can show/link them separately.
        const match = label.match(/^(.*)\s\(([^)]*)\)$/);
        let fullname = label;
        let shortname = '';
        if (match) {
            fullname = match[1];
            shortname = match[2];
        }
        setState({
            selectedCourseId: id,
            selectedCourse: {id, fullname, shortname},
            courseStructure: null,
        });
    });
};
