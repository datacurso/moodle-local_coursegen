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
 * Step 4: Limits — binds events on server-rendered controls.
 *
 * @module     local_coursegen/local/template/step_limits
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

let bound = false;

/**
 * Bind events on the server-rendered limits step.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepLimits = (panel, state) => {
    if (bound) {
        return;
    }
    bound = true;

    const structure = state.courseStructure || [];
    const present = new Set();
    structure.forEach(s => s.activities.forEach(a => present.add(a.modname)));

    // Set initial values on server-rendered inputs.
    const maxInput = panel.querySelector('[data-field="max-sections"]');
    const noLimitCb = panel.querySelector('[data-field="no-limit"]');
    if (maxInput) {
        maxInput.value = state.maxSections || structure.length;
    }
    if (noLimitCb) {
        noLimitCb.checked = state.noLimit;
        if (maxInput) {
            maxInput.disabled = state.noLimit;
        }
    }

    // Pre-check types that are in the course and set allowedTypes.
    panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => {
        const inCourse = present.has(cb.dataset.type);
        if (inCourse) {
            cb.checked = true;
            if (!state.allowedTypes.includes(cb.dataset.type)) {
                state.allowedTypes.push(cb.dataset.type);
            }
            // Add badge for types present in course.
            const label = cb.nextElementSibling;
            if (label && !label.querySelector('.badge')) {
                label.insertAdjacentHTML('beforeend',
                    ' <span class="badge badge-info badge-pill" title="Present in course">&#10003;</span>');
            }
        } else {
            cb.checked = state.allowedTypes.includes(cb.dataset.type);
        }
    });

    updateHint(panel, state, structure);
    updatePreview(panel, state, structure);

    // Bind events.
    maxInput?.addEventListener('change', (e) => {
        state.maxSections = parseInt(e.target.value) || structure.length;
        setState(state);
        updateHint(panel, state, structure);
    });

    noLimitCb?.addEventListener('change', (e) => {
        state.noLimit = e.target.checked;
        if (maxInput) {
            maxInput.disabled = e.target.checked;
        }
        setState(state);
        updateHint(panel, state, structure);
    });

    panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked && !state.allowedTypes.includes(cb.dataset.type)) {
                state.allowedTypes.push(cb.dataset.type);
            } else {
                state.allowedTypes = state.allowedTypes.filter(t => t !== cb.dataset.type);
            }
            setState(state);
        });
    });

    panel.querySelector('[data-action="select-all-types"]')?.addEventListener('click', (e) => {
        e.preventDefault();
        panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => { cb.checked = true; });
        state.allowedTypes = [...new Set([...state.allowedTypes,
            ...Array.from(panel.querySelectorAll('[data-action="toggle-type"]')).map(cb => cb.dataset.type)
        ])];
        setState(state);
    });

    panel.querySelector('[data-action="deselect-all-types"]')?.addEventListener('click', (e) => {
        e.preventDefault();
        panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => { cb.checked = false; });
        state.allowedTypes = [];
        setState(state);
    });

    panel.querySelector('[data-field="naming-pattern"]')?.addEventListener('change', (e) => {
        const customBlock = panel.querySelector('[data-region="custom-pattern"]');
        if (e.target.value === '__custom__') {
            customBlock.classList.remove('d-none');
            state.namingPattern = panel.querySelector('[data-field="custom-pattern"]')?.value || '{nombre}';
        } else {
            customBlock.classList.add('d-none');
            state.namingPattern = e.target.value;
        }
        setState(state);
        updatePreview(panel, state, structure);
    });

    panel.querySelector('[data-field="custom-pattern"]')?.addEventListener('input', (e) => {
        state.namingPattern = e.target.value || '{nombre}';
        setState(state);
        updatePreview(panel, state, structure);
    });

    panel.querySelector('[data-field="naming-start"]')?.addEventListener('change', (e) => {
        state.namingStart = parseInt(e.target.value);
        setState(state);
        updatePreview(panel, state, structure);
    });
};

/**
 * Update the sections hint text.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const updateHint = (panel, state, structure) => {
    const h = panel.querySelector('[data-region="sections-hint"]');
    if (!h) {
        return;
    }
    const orig = 'Original course has <strong>' + structure.length + '</strong> sections. ';
    if (state.noLimit) {
        h.innerHTML = orig + 'AI can create any number of sections.';
    } else if (state.maxSections < structure.length) {
        h.innerHTML = orig + 'AI will merge extra sections.';
    } else if (state.maxSections > structure.length) {
        h.innerHTML = orig + 'AI may add additional sections.';
    } else {
        h.innerHTML = orig + 'Same number as the original.';
    }
};

/**
 * Update the naming preview.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const updatePreview = (panel, state, structure) => {
    const c = panel.querySelector('[data-region="naming-preview"]');
    if (!c) {
        return;
    }
    let html = '<small class="text-muted d-block mb-1">Preview:</small>';
    structure.forEach((sec, i) => {
        const n = state.namingStart + i;
        const rendered = state.namingPattern.replace(/\{N\}/g, n).replace(/\{nombre\}/g, sec.name);
        html += '<small class="d-block">' + rendered + '</small>';
    });
    c.innerHTML = html;
};
