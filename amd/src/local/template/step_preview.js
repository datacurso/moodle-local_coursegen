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
 * Step 2: Course preview using native format renderer + template naming.
 *
 * @module     local_coursegen/local/template/step_preview
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getCoursePreview} from './repository';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

let lastRenderedCourseId = null;

/**
 * Render step 2 panel — native course preview (read-only).
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepPreview = async(panel, state) => {
    const slot = panel.querySelector('[data-region="course-preview"]');

    // Already has content (server-rendered or previous visit).
    if (slot && slot.hasChildNodes()) {
        if (lastRenderedCourseId && lastRenderedCourseId !== state.selectedCourseId) {
            // Course changed since last render — clear and re-fetch below.
            slot.innerHTML = '';
        } else {
            // Same course or first load with server HTML — just bind events.
            lastRenderedCourseId = state.selectedCourseId;
            bindCollapseEvents(panel);
            return;
        }
    }

    const heading = await getString('template_course_preview', 'local_coursegen');

    panel.innerHTML = `<h4>${heading}</h4>
        <div class="d-flex align-items-center py-5 justify-content-center">
            <div class="spinner-border text-primary mr-2" role="status"></div>
            <span class="text-muted">Loading course preview...</span>
        </div>`;

    try {
        const preview = await getCoursePreview(state.selectedCourseId);
        lastRenderedCourseId = state.selectedCourseId;

        const courseInfo = `${preview.fullname} (${preview.shortname})`;
        const stats = `${preview.numsections} sections, ${preview.numactivities} activities`;
        const viewUrl = M.cfg.wwwroot + '/course/view.php?id=' + preview.courseid;

        panel.innerHTML = `
            <h4>${heading}</h4>
            <p class="text-muted">
                ${courseInfo} &mdash; ${stats}
                <a href="${viewUrl}" target="_blank" class="ml-2">
                    <i class="fa fa-external-link"></i>
                </a>
            </p>
            <div data-region="course-preview">
                ${preview.html}
            </div>`;

        bindCollapseEvents(panel);
    } catch (e) {
        Notification.exception(e);
        panel.innerHTML = `<h4>${heading}</h4>
            <div class="alert alert-danger">Failed to load course preview.</div>`;
    }
};

/**
 * Strip reactive data attributes so Bootstrap collapse works natively.
 *
 * @param {HTMLElement} panel
 */
const bindCollapseEvents = (panel) => {
    const preview = panel.querySelector('[data-region="course-preview"]');
    if (!preview) {
        return;
    }

    // Remove data-for attributes that the reactive component would intercept.
    // This lets Bootstrap's native data-toggle="collapse" handle everything.
    preview.querySelectorAll('[data-for="sectiontoggler"]').forEach(el => {
        el.removeAttribute('data-for');
    });

    // "Collapse all" / "Expand all" — handle manually since it uses data-toggle="toggleall".
    preview.querySelectorAll('[data-toggle="toggleall"]').forEach(link => {
        link.removeAttribute('data-toggle');
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const togglers = preview.querySelectorAll('[data-toggle="collapse"]');
            const allExpanded = [...togglers].every(t => !t.classList.contains('collapsed'));
            togglers.forEach(tog => {
                const targetId = tog.getAttribute('href');
                const target = preview.querySelector(targetId);
                if (!target) {
                    return;
                }
                if (allExpanded) {
                    target.classList.remove('show');
                    tog.classList.add('collapsed');
                    tog.setAttribute('aria-expanded', 'false');
                } else {
                    target.classList.add('show');
                    tog.classList.remove('collapsed');
                    tog.setAttribute('aria-expanded', 'true');
                }
            });
        });
    });
};
