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
 * Course-content list container for the detailed plan preview.
 *
 * Wraps the section <li> rows in the same ul.course-content markup Moodle's
 * core_courseformat renders, plus the format-topics/course-content ancestor
 * classes some Boost rules expect, so the loaded theme styles the preview
 * exactly like a real "Custom sections" course view.
 *
 * @module     local_coursegen/local/courseai/detailed/container
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Get (creating if needed) the ul.course-content list inside #prvSections.
 *
 * The host #prvSections element is cleared by initDetailedPlanView; this lazily
 * (re)creates the Moodle course-content scaffold the first time a section is
 * appended after a reset.
 *
 * @param {Object} ctx
 * @returns {HTMLUListElement|null}
 */
export const getSectionList = (ctx) => {
    const host = ctx.elements.prvSections;
    if (!host) {
        return null;
    }
    let list = host.querySelector('ul.course-content');
    if (!list) {
        // Scope wrapper: gives the preview the course-page ancestor classes so
        // any Boost rule scoped under .course-content / .format-topics applies.
        // `editing` is added LOCALLY (Boost scopes its edit affordances as
        // `.editing &`, i.e. any `.editing` ancestor) so the preview is ALWAYS
        // editable — drag handles, hover affordances and inline controls work
        // regardless of Moodle's global edit-mode toggle. The preview is never a
        // read-only course view, so it must not depend on the page edit mode.
        host.classList.add('format-topics', 'cg-course-replica', 'editing');
        list = document.createElement('ul');
        list.className = 'course-content course-section-list';
        host.appendChild(list);
    }
    return list;
};
