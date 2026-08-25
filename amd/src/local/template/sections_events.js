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
 * Event bindings for server-rendered section config controls.
 *
 * @module     local_coursegen/local/template/sections_events
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

/** @type {boolean} Whether any config has been modified. */
let dirty = false;

/**
 * Mark the wizard as having unsaved changes.
 */
const markDirty = () => {
    if (!dirty) {
        dirty = true;
        window.addEventListener('beforeunload', onBeforeUnload);
    }
};

/**
 * Handler for beforeunload event.
 *
 * @param {Event} e
 */
const onBeforeUnload = (e) => {
    e.preventDefault();
};

/**
 * Bind events on server-rendered controls (no DOM injection needed).
 *
 * @param {HTMLElement} container
 * @param {Object} state
 */
export const bindServerRenderedControls = (container, state) => {
    // Section dropdowns.
    container.querySelectorAll('[data-sec-action]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const sid = parseInt(item.dataset.sid);
            state.sectionBehavior[sid] = item.dataset.secAction;
            setState(state);
            markDirty();
            const section = container.querySelector(`[data-for="section"][data-id="${sid}"]`);
            if (section) {
                applySectionVisual(section, item.dataset.secAction, container);
            }
            const btn = item.closest('.dropdown')?.querySelector('.dropdown-toggle');
            if (btn) {
                btn.textContent = item.textContent;
                const colors = {custom: '#0f6cbf', keep: '#28a745', exclude: '#6c757d'};
                btn.style.color = colors[item.dataset.secAction] || '#0f6cbf';
            }
        });
    });

    // Activity dropdowns.
    container.querySelectorAll('[data-act-val]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const cmitem = item.closest('[data-for="cmitem"]');
            const cmid = cmitem ? parseInt(cmitem.dataset.id) : 0;
            if (!cmid) {
                return;
            }
            state.activityAction[cmid] = item.dataset.actVal;
            setState(state);
            markDirty();
            cmitem.style.opacity = item.dataset.actVal === 'exclude' ? '0.35' : '1';
            const btn = item.closest('.dropdown')?.querySelector('.dropdown-toggle');
            if (btn) {
                btn.textContent = item.textContent;
                const colors = {modify: '#0f6cbf', keep: '#28a745', reference: '#6f42c1', exclude: '#6c757d'};
                btn.style.color = colors[item.dataset.actVal] || '#0f6cbf';
            }
            const pw = container.querySelector(`[data-tpl-prompt-wrap="${cmid}"]`);
            if (pw) {
                pw.style.display = item.dataset.actVal === 'modify' ? '' : 'none';
            }
        });
    });

    // Prompt textareas.
    container.querySelectorAll('[data-tpl-prompt]').forEach(ta => {
        ta.addEventListener('input', () => {
            const cmid = parseInt(ta.dataset.tplPrompt);
            state.activityPrompt[cmid] = ta.value;
            setState(state);
            markDirty();
        });
    });
};

/**
 * Apply visual state to a section.
 *
 * @param {HTMLElement} section
 * @param {string} behavior
 *
 */
const applySectionVisual = (section, behavior) => {
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
