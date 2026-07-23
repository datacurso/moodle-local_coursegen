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
 * Step 3: Configure sections/activities on the native format renderer view.
 *
 * @module     local_coursegen/local/template/step_sections
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';
import {getCoursePreview} from './repository';
import {appendPrompt} from './prompt_editor';
import Notification from 'core/notification';

const SEC_TIPS = {
    custom: 'Configure each activity individually',
    keep: 'Copy this entire section exactly as-is to the new course',
    exclude: 'This section will not appear in generated courses',
};
const ACT_LABELS = {modify: 'Modify', keep: 'Keep', reference: 'Reference', exclude: 'Exclude'};
const ACT_COLORS = {modify: '#0f6cbf', keep: '#28a745', reference: '#6f42c1', exclude: '#6c757d'};
const ACT_TIPS = {
    modify: 'AI will generate new content based on this activity',
    keep: 'This activity will be copied exactly as-is',
    reference: 'AI will use this as reference for tone and depth — not copied',
    exclude: 'This activity will not appear in generated courses',
};

let rendered = false;

/**
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepSections = async(panel, state) => {
    if (rendered && panel.querySelector('[data-region="sections-config"]')) {
        return;
    }
    panel.innerHTML = `<h4>Configure sections</h4>
        <p class="text-muted">Choose what AI should do with each part of the course</p>
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
        container.querySelectorAll('[data-for="sectiontoggler"]').forEach(el => el.removeAttribute('data-for'));
        // Hide "Collapse all" links.
        container.querySelectorAll('[data-toggle="toggleall"]').forEach(el => { el.style.display = 'none'; });
        injectSectionControls(container, state);
        injectActivityControls(container, state);
        rendered = true;
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * @param {HTMLElement} container
 * @param {Object} state
 */
const injectSectionControls = (container, state) => {
    container.querySelectorAll('[data-for="section"]').forEach(section => {
        const sid = parseInt(section.dataset.id);
        if (!sid) { return; }
        const beh = state.sectionBehavior[sid] || 'custom';
        const titleBar = section.querySelector('[data-for="section_title"]');
        if (!titleBar) { return; }
        const wrap = document.createElement('div');
        wrap.className = 'ml-auto d-flex align-items-center';
        wrap.innerHTML = buildSectionBtnGroup(sid, beh);
        titleBar.appendChild(wrap);
        applySectionState(section, beh);
        bindSectionButtons(wrap, sid, section, state, container);
    });
};

/**
 * @param {HTMLElement} wrap
 * @param {number} sid
 * @param {HTMLElement} sec
 * @param {Object} st
 * @param {HTMLElement} c
 */
const bindSectionButtons = (wrap, sid, sec, st, c) => {
    wrap.querySelectorAll('[data-sec-action]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            st.sectionBehavior[sid] = item.dataset.secAction;
            setState(st);
            wrap.innerHTML = buildSectionBtnGroup(sid, item.dataset.secAction);
            applySectionState(sec, item.dataset.secAction);
            bindSectionButtons(wrap, sid, sec, st, c);
        });
    });
};

/**
 * @param {number} sid
 * @param {string} active
 * @returns {string}
 */
const buildSectionBtnGroup = (sid, active) => {
    const labels = {custom: 'Customise', keep: 'Keep intact', exclude: 'Exclude'};
    const colors = {custom: '#0f6cbf', keep: '#28a745', exclude: '#6c757d'};
    const color = colors[active];
    let html = `<div class="dropdown">
        <button class="btn btn-sm btn-link dropdown-toggle p-0" style="color:${color};text-decoration:none;font-weight:600"
                data-toggle="dropdown" title="${SEC_TIPS[active]}">${labels[active]}</button>
        <div class="dropdown-menu dropdown-menu-right">`;
    Object.keys(labels).forEach(key => {
        const act = key === active ? 'active' : '';
        html += `<a class="dropdown-item ${act}" href="#" data-sec-action="${key}" data-sid="${sid}"
                    title="${SEC_TIPS[key]}">${labels[key]}</a>`;
    });
    return html + '</div></div>';
};

/**
 * @param {HTMLElement} section
 * @param {string} behavior
 */
const applySectionState = (section, behavior) => {
    // Only dim the content area, not the header/dropdown.
    const content = section.querySelector('.content') || section.querySelector('.course-content-item-content');
    if (content) {
        content.style.opacity = behavior === 'keep' ? '0.5' : behavior === 'exclude' ? '0.3' : '1';
    }
    section.querySelectorAll('[data-tpl-control]').forEach(c => {
        c.style.display = behavior === 'custom' ? '' : 'none';
    });
    section.querySelectorAll('[data-tpl-prompt-wrap]').forEach(c => {
        c.style.display = behavior === 'custom' ? '' : 'none';
    });
};

/**
 * @param {HTMLElement} container
 * @param {Object} state
 */
const injectActivityControls = (container, state) => {
    container.querySelectorAll('[data-for="cmitem"]').forEach(cmitem => {
        const cmid = parseInt(cmitem.dataset.id);
        if (!cmid) { return; }
        const action = state.activityAction[cmid] || 'modify';
        const prompt = state.activityPrompt[cmid] || '';
        const grid = cmitem.querySelector('.activity-grid') || cmitem;

        // Dropdown trigger — text with color, no borders.
        const trigger = document.createElement('div');
        trigger.setAttribute('data-tpl-control', cmid);
        trigger.className = 'ml-auto dropdown';
        renderDropdown(trigger, cmid, action, state, container);
        grid.appendChild(trigger);

        // Prompt — borderless input with only a bottom line.
        if (action === 'modify') {
            appendPrompt(cmitem, cmid, prompt, state);
        }
    });
};

/**
 * @param {HTMLElement} el
 * @param {number} cmid
 * @param {string} action
 * @param {Object} st
 * @param {HTMLElement} c
 */
const renderDropdown = (el, cmid, action, st, c) => {
    const color = ACT_COLORS[action];
    const label = ACT_LABELS[action];
    let html = `<button class="btn btn-sm btn-link dropdown-toggle p-0" style="color:${color};text-decoration:none"
        data-toggle="dropdown" title="${ACT_TIPS[action]}">${label}</button>`;
    html += '<div class="dropdown-menu dropdown-menu-right">';
    Object.keys(ACT_LABELS).forEach(key => {
        const active = key === action ? 'active' : '';
        html += `<a class="dropdown-item ${active}" href="#" data-act-val="${key}"
                    title="${ACT_TIPS[key]}">${ACT_LABELS[key]}</a>`;
    });
    html += '</div>';
    el.innerHTML = html;

    el.querySelectorAll('[data-act-val]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const val = item.dataset.actVal;
            st.activityAction[cmid] = val;
            if (val === 'modify') { st.activityRef[cmid] = true; }
            setState(st);

            renderDropdown(el, cmid, val, st, c);

            const cmitem = c.querySelector(`[data-for="cmitem"][data-id="${cmid}"]`);
            if (!cmitem) { return; }
            cmitem.style.opacity = val === 'exclude' ? '0.35' : '1';

            // Toggle prompt.
            const existing = cmitem.querySelector(`[data-tpl-prompt-wrap="${cmid}"]`);
            if (existing) { existing.remove(); }
            if (val === 'modify') {
                appendPrompt(cmitem, cmid, st.activityPrompt[cmid] || '', st);
            }
        });
    });
};

