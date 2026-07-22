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
 * Step 3: Configure sections and activities using the native course format view
 * with action controls overlaid on each section and activity.
 *
 * @module     local_coursegen/local/template/step_sections
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';
import {getCoursePreview} from './repository';
import Notification from 'core/notification';

let rendered = false;

/**
 * Render step 3 panel.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepSections = async(panel, state) => {
    if (rendered && panel.querySelector('[data-region="sections-config"]')) {
        return;
    }

    panel.innerHTML = `<h4>Configure sections</h4>
        <p class="text-muted">Decide what AI does with each part of the course</p>
        <div data-region="sections-config">
            <div class="d-flex align-items-center py-5 justify-content-center">
                <div class="spinner-border text-primary mr-2" role="status"></div>
                <span class="text-muted">Loading course structure...</span>
            </div>
        </div>`;

    try {
        const preview = await getCoursePreview(state.selectedCourseId);
        const container = panel.querySelector('[data-region="sections-config"]');
        container.innerHTML = preview.html;

        // Remove reactive attributes to prevent server calls.
        container.querySelectorAll('[data-for="sectiontoggler"]').forEach(el => el.removeAttribute('data-for'));

        injectSectionControls(container, state);
        injectActivityControls(container, state);
        rendered = true;
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Inject behavior controls into each section header.
 *
 * @param {HTMLElement} container
 * @param {Object} state
 */
const injectSectionControls = (container, state) => {
    container.querySelectorAll('[data-for="section"]').forEach(section => {
        const sectionId = parseInt(section.dataset.id);
        if (!sectionId) {
            return;
        }
        const behavior = state.sectionBehavior[sectionId] || 'custom';

        // Find the section title area and append the control.
        const titleBar = section.querySelector('[data-for="section_title"]');
        if (!titleBar) {
            return;
        }

        const control = document.createElement('div');
        control.className = 'ml-auto d-flex align-items-center';
        control.innerHTML = `<select class="custom-select custom-select-sm" style="width:auto"
                data-action="section-behavior" data-sid="${sectionId}">
            <option value="custom" ${behavior === 'custom' ? 'selected' : ''}>Customise</option>
            <option value="keep" ${behavior === 'keep' ? 'selected' : ''}>Keep intact</option>
            <option value="exclude" ${behavior === 'exclude' ? 'selected' : ''}>Exclude</option>
        </select>`;
        titleBar.appendChild(control);

        // Apply visual state.
        applySectionState(section, behavior);

        // Bind change event.
        control.querySelector('select').addEventListener('change', (e) => {
            state.sectionBehavior[sectionId] = e.target.value;
            setState(state);
            applySectionState(section, e.target.value);
        });
    });
};

/**
 * Apply visual state to a section based on its behavior.
 *
 * @param {HTMLElement} section
 * @param {string} behavior
 */
const applySectionState = (section, behavior) => {
    const cmlist = section.querySelector('[data-for="cmlist"]')
        || section.querySelector('.course-content-item-content');

    if (behavior === 'keep') {
        section.style.opacity = '0.6';
        if (cmlist) {
            cmlist.querySelectorAll('[data-tpl-control]').forEach(c => c.classList.add('d-none'));
        }
    } else if (behavior === 'exclude') {
        section.style.opacity = '0.35';
        if (cmlist) {
            cmlist.querySelectorAll('[data-tpl-control]').forEach(c => c.classList.add('d-none'));
        }
    } else {
        section.style.opacity = '1';
        if (cmlist) {
            cmlist.querySelectorAll('[data-tpl-control]').forEach(c => c.classList.remove('d-none'));
        }
    }
};

/**
 * Inject action controls below each activity.
 *
 * @param {HTMLElement} container
 * @param {Object} state
 */
const injectActivityControls = (container, state) => {
    container.querySelectorAll('[data-for="cmitem"]').forEach(cmitem => {
        const cmid = parseInt(cmitem.dataset.id);
        if (!cmid) {
            return;
        }
        const action = state.activityAction[cmid] || 'modify';
        const prompt = state.activityPrompt[cmid] || '';

        const control = document.createElement('div');
        control.setAttribute('data-tpl-control', cmid);
        control.className = 'pl-5 pr-3 pb-2 small';
        control.innerHTML = buildActivityControl(cmid, action, prompt);
        cmitem.appendChild(control);

        bindActivityEvents(control, cmid, state, container);
    });
};

/**
 * Build the HTML for an activity control panel.
 *
 * @param {number} cmid
 * @param {string} action
 * @param {string} prompt
 * @returns {string}
 */
const buildActivityControl = (cmid, action, prompt) => {
    let html = `<div class="d-flex align-items-center flex-wrap">
        <select class="custom-select custom-select-sm mr-2" style="width:auto"
                data-action="activity-action" data-aid="${cmid}">
            <option value="modify" ${action === 'modify' ? 'selected' : ''}>Modify with AI</option>
            <option value="keep" ${action === 'keep' ? 'selected' : ''}>Keep intact</option>
            <option value="reference" ${action === 'reference' ? 'selected' : ''}>Reference only</option>
            <option value="exclude" ${action === 'exclude' ? 'selected' : ''}>Exclude</option>
        </select>
    </div>`;

    if (action === 'modify') {
        html += `<div class="mt-1 border-left pl-3 ml-1" style="border-color:#0f6cbf!important">
            <textarea class="form-control form-control-sm" rows="1"
                      data-action="activity-prompt" data-aid="${cmid}"
                      placeholder="E.g.: Adapt content for sales training">${prompt}</textarea>
        </div>`;
    }

    return html;
};

/**
 * Bind events for a single activity control.
 *
 * @param {HTMLElement} control
 * @param {number} cmid
 * @param {Object} state
 * @param {HTMLElement} container
 */
const bindActivityEvents = (control, cmid, state, container) => {
    const select = control.querySelector('[data-action="activity-action"]');
    select?.addEventListener('change', () => {
        state.activityAction[cmid] = select.value;
        if (select.value === 'modify') {
            state.activityRef[cmid] = true;
        }
        setState(state);
        // Re-render just this control.
        control.innerHTML = buildActivityControl(cmid, select.value, state.activityPrompt[cmid] || '');
        bindActivityEvents(control, cmid, state, container);

        // Dim activity if excluded.
        const cmitem = container.querySelector(`[data-for="cmitem"][data-id="${cmid}"]`);
        if (cmitem) {
            cmitem.style.opacity = select.value === 'exclude' ? '0.35' : '1';
        }
    });

    const textarea = control.querySelector('[data-action="activity-prompt"]');
    textarea?.addEventListener('input', () => {
        state.activityPrompt[cmid] = textarea.value;
        setState(state);
    });
};
