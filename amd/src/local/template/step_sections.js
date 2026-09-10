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
 * Section/activity structure review — renders the native course-format view
 * with the configuration controls Moodle already injected server-side
 * (see classes/output/sections_config.php), for both the initial page load
 * and the AJAX course-selection path (get_course_preview always runs
 * through the same server-side renderer, so there is only ONE place that
 * builds these controls, not a client-side copy that can drift from it).
 *
 * @module     local_coursegen/local/template/step_sections
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getCoursePreview} from './repository';
import {bindServerRenderedControls} from './sections_events';
import {applyTypeDefaultsToState} from './type_action_sync';
import Notification from 'core/notification';

let rendered = false;

/** Reset so a newly selected course's structure gets rendered again. */
export const resetSectionsRender = () => { rendered = false; };

/**
 * @param {HTMLElement} panel The structure panel (holds [data-region="sections-config"]).
 * @param {Object} state
 * @param {boolean} isFreshFromPageLoad Whether edit_template.php just server-rendered
 *     this exact panel for the currently selected course (init.js's own
 *     configFormIsFreshFromPageLoad, read before it resets that flag back to
 *     false) — the ONLY case where reusing whatever markup already sits in
 *     the container is actually correct. Every other call is either a course
 *     switch (the container still holds the PREVIOUSLY selected course's
 *     markup, which happens to match the exact same selector this used to
 *     rely on to guess "already rendered") or a course picked without a
 *     preset, so this must come from the caller — inferring it from the
 *     container's own content, however plausible-looking, can't tell "fresh
 *     for THIS course" apart from "stale from a DIFFERENT one".
 */
export const renderStepSections = async(panel, state, isFreshFromPageLoad) => {
    if (rendered) {
        return;
    }

    let container = panel.querySelector('[data-region="sections-config"]');

    // Server already rendered the controls on initial page load — just bind.
    if (isFreshFromPageLoad && container && container.querySelector('[data-sec-action], [data-act-val]')) {
        applyTypeDefaultsToState(container, state);
        bindServerRenderedControls(container, state);
        rendered = true;
        return;
    }

    // Otherwise fetch via AJAX (course picked without a full page reload,
    // or a DIFFERENT course selected after the initial one).
    container = panel.querySelector('[data-region="sections-config"]') || panel;
    container.innerHTML = `<div class="d-flex align-items-center py-5 justify-content-center">
            <div class="spinner-border text-primary mr-2" role="status"></div>
            <span class="text-muted">Loading course structure...</span>
        </div>`;
    try {
        const preview = await getCoursePreview(state.selectedCourseId);
        container.innerHTML = preview.html;
        applyTypeDefaultsToState(container, state);
        bindServerRenderedControls(container, state);
        rendered = true;
    } catch (e) {
        Notification.exception(e);
    }
};
