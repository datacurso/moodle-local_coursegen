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
import {applyKindDefaultsToState} from './kind_defaults';
import Notification from 'core/notification';

let rendered = false;
/** Reset so a newly selected course's structure gets rendered again. */
export const resetSectionsRender = () => { rendered = false; };

/**
 * @param {HTMLElement} panel The structure panel (holds [data-region="sections-config"]).
 * @param {Object} state
 */
export const renderStepSections = async(panel, state) => {
    if (rendered) {
        return;
    }

    let container = panel.querySelector('[data-region="sections-config"]');

    // Server already rendered the controls on initial page load — just bind.
    if (container && container.querySelector('[data-sec-action], [data-act-val]')) {
        applyKindDefaultsToState(container, state);
        bindServerRenderedControls(container, state);
        rendered = true;
        return;
    }

    // Otherwise fetch via AJAX (course picked without a full page reload).
    container = panel.querySelector('[data-region="sections-config"]') || panel;
    container.innerHTML = `<div class="d-flex align-items-center py-5 justify-content-center">
            <div class="spinner-border text-primary mr-2" role="status"></div>
            <span class="text-muted">Loading course structure...</span>
        </div>`;
    try {
        const preview = await getCoursePreview(state.selectedCourseId);
        container.innerHTML = preview.html;
        applyKindDefaultsToState(container, state);
        bindServerRenderedControls(container, state);
        rendered = true;
    } catch (e) {
        Notification.exception(e);
    }
};
