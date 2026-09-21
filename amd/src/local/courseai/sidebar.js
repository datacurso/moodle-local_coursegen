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
 * Sidebar component for the AI course creation page. Orchestrates the
 * sessions list (sessions_list.js) and the sidebar's own open/closed/
 * floating layout (sidebar_layout.js), and wires the two controls that
 * don't belong to either: "New course" and clicking a recent course.
 *
 * @module     local_coursegen/local/courseai/sidebar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {wireSessionsList} from 'local_coursegen/local/courseai/sessions_list';
import {wireSidebarLayout} from 'local_coursegen/local/courseai/sidebar_layout';

/**
 * Initialize the sidebar component.
 */
export const initSidebar = () => {
    const sidebar = document.getElementById('courseaiSidebar');
    if (!sidebar) {
        return;
    }

    wireSessionsList();
    wireSidebarLayout(sidebar);

    // ─── New course button ───────────────────────────────────────────
    // A clean page, no query string: the creation mode is chosen in the
    // composer (mode_switch partial), so nothing from the current page
    // carries over. No closeSidebar() here: the page navigates away at
    // once, so collapsing first only flashes the close animation.
    const btnNew = document.getElementById('courseaiBtnNew');
    if (btnNew) {
        btnNew.addEventListener('click', () => {
            window.location.href = new URL('aicoursecreation.php', window.location.href).toString();
        });
    }

    // ─── Course items ────────────────────────────────────────────────
    const courseItems = sidebar.querySelectorAll('.courseai-sidebar-course-item');
    courseItems.forEach((item) => {
        item.addEventListener('click', () => {
            const sessionid = item.dataset.sessionid;
            if (sessionid) {
                window.location.href = `aicoursecreation.php?sessionid=${sessionid}`;
            }
        });
    });
};
