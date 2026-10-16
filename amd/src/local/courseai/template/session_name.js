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
 * Shows the name the AI returned for a generation in the list of recent courses.
 *
 * The name is the first thing the run returns. It is saved on the session, so the list shows it on the next
 * page load, and written into the list on the page right away.
 *
 * @module     local_coursegen/local/courseai/template/session_name
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {nameTemplateSession} from 'local_coursegen/local/courseai/template/repository';
import Notification from 'core/notification';

/**
 * Put the name on the entries of a session, in the sidebar and in the full list.
 *
 * @param {number} sessionId
 * @param {string} fullname
 * @param {string} shortname
 */
const paintName = (sessionId, fullname, shortname) => {
    const items = document.querySelectorAll(`[data-sessionid="${sessionId}"]`);
    items.forEach((item) => {
        item.dataset.title = fullname;
        const title = item.querySelector('.courseai-sidebar-course-title, .courseai-session-row-name a');
        if (title) {
            title.textContent = fullname;
        }
        const subtitle = item.querySelector('.courseai-sidebar-course-shortname');
        if (subtitle) {
            subtitle.textContent = shortname;
            subtitle.hidden = shortname === '';
        }
    });
};

/**
 * The AI returned the name of the course: save it on the session and show it in the list.
 *
 * @param {number} sessionId
 * @param {Object} data The course_configuration event, {fullname, shortname}.
 */
export const applySessionName = (sessionId, data) => {
    const fullname = String(data.fullname || '').trim();
    const shortname = String(data.shortname || '').trim();
    if (!fullname || !sessionId) {
        return;
    }
    paintName(sessionId, fullname, shortname);
    // The list is only a convenience: failing to save the name must not stop the generation.
    nameTemplateSession(sessionId, fullname, shortname).catch(Notification.exception);
};
