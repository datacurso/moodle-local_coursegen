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
 * The links that open what a generation is going to produce.
 *
 * Both previews read one run's answer, so neither exists before a run does:
 * the controls are in the page from the start and stay hidden until a session
 * is opened, rather than being built at that moment, so the structure is
 * rendered once and never rebuilt just to add a link to it.
 *
 * @module     local_coursegen/local/courseai/template/preview
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The session whose answer the previews read; 0 before a run is opened. */
let sessionId = 0;

/**
 * Point every preview control at one session, and reveal them.
 *
 * Called when a generation opens. Re-rendering the structure drops the state
 * of its rows, so this is called again after each render.
 *
 * @param {number} id The session id, or 0 to hide the controls again.
 */
export const usePreviewSession = (id) => {
    sessionId = Number(id) || 0;
    refreshPreviewLinks();
};

/**
 * Show or hide the preview controls, according to whether there is a run.
 */
export const refreshPreviewLinks = () => {
    const course = document.getElementById('tplPreviewCourse');
    if (course) {
        course.hidden = sessionId <= 0;
        course.href = sessionId > 0
            ? M.cfg.wwwroot + '/local/coursegen/course_preview.php?sessionid=' + sessionId
            : '#';
    }

    document.querySelectorAll('[data-action="local_coursegen/template/preview-activity"]')
        .forEach((link) => {
            const uid = link.dataset.generationUid;
            link.hidden = sessionId <= 0 || !uid;
            // The name the answer gives this activity, passed along untouched.
            link.href = sessionId > 0 && uid
                ? M.cfg.wwwroot + '/local/coursegen/activity_preview.php'
                    + '?sessionid=' + sessionId + '&uid=' + encodeURIComponent(uid)
                : '#';
            link.target = '_blank';
            link.rel = 'noopener';
        });
};
