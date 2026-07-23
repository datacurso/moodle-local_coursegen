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
 * Step 4: Generated course limits.
 *
 * @module     local_coursegen/local/template/step_limits
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

const TYPES = [
    'forum', 'assign', 'quiz', 'resource', 'lesson', 'book', 'glossary', 'workshop',
    'url', 'wiki', 'page', 'label', 'feedback', 'choice', 'data', 'folder', 'h5pactivity', 'scorm', 'imscp',
];

const PRESETS = [
    {value: 'Unidad {N} \u2014 {nombre}', label: 'Unidad {N} \u2014 {nombre}'},
    {value: 'M\u00f3dulo {N}: {nombre}', label: 'M\u00f3dulo {N}: {nombre}'},
    {value: 'Tema {N}: {nombre}', label: 'Tema {N}: {nombre}'},
    {value: 'Semana {N}: {nombre}', label: 'Semana {N}: {nombre}'},
    {value: '{nombre}', label: '{nombre} (name only)'},
    {value: '__custom__', label: 'Custom...'},
];

/**
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepLimits = (panel, state) => {
    const structure = state.courseStructure || [];
    const present = new Set();
    structure.forEach(s => s.activities.forEach(a => present.add(a.modname)));
    const custom = !PRESETS.find(p => p.value !== '__custom__' && p.value === state.namingPattern);

    let html = '<h4>Course generation limits</h4>';
    html += '<p class="text-muted mb-4">Configure constraints for the AI-generated course</p>';

    // Maximum sections — simple row like a moodleform fitem.
    html += '<fieldset class="border rounded p-3 mb-3"><legend class="w-auto px-2 h6">Maximum sections</legend>';
    html += `<p class="text-muted small">The original course has <strong>${structure.length}</strong> sections</p>`;
    html += '<div class="form-group row">';
    html += '<div class="col-md-4"><label class="col-form-label">Sections limit</label></div>';
    html += `<div class="col-md-8 d-flex align-items-center">
        <input type="number" class="form-control" style="width:100px" data-field="max-sections"
               value="${state.maxSections}" min="1" max="50" ${state.noLimit ? 'disabled' : ''}>
        <div class="custom-control custom-checkbox ml-3">
            <input type="checkbox" class="custom-control-input" id="tpl-no-limit" data-field="no-limit"
                   ${state.noLimit ? 'checked' : ''}>
            <label class="custom-control-label" for="tpl-no-limit">No limit</label>
        </div>
    </div></div>`;
    html += '<p class="text-muted small mb-0" data-region="sections-hint"></p>';
    html += '</fieldset>';

    // Allowed activity types.
    html += '<fieldset class="border rounded p-3 mb-3"><legend class="w-auto px-2 h6">Allowed activity types</legend>';
    html += '<p class="text-muted small mb-3">Select which activity types AI can use in the generated course</p>';
    html += '<div class="row">';
    TYPES.forEach(t => {
        const label = t.charAt(0).toUpperCase() + t.slice(1);
        const checked = state.allowedTypes.includes(t);
        const inCourse = present.has(t);
        html += `<div class="col-6 col-md-3 mb-2">
            <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="tpl-type-${t}"
                       data-action="toggle-type" data-type="${t}" ${checked ? 'checked' : ''}>
                <label class="custom-control-label" for="tpl-type-${t}">
                    ${label}${inCourse
                        ? ' <span class="badge badge-info badge-pill ml-1" title="In course">&#10003;</span>'
                        : ''}
                </label>
            </div>
        </div>`;
    });
    html += '</div>';
    html += `<div class="mt-2">
        <button class="btn btn-outline-secondary btn-sm mr-1" data-action="select-all-types">Select all</button>
        <button class="btn btn-outline-secondary btn-sm" data-action="deselect-all-types">Deselect all</button>
    </div></fieldset>`;

    // Section naming pattern.
    html += '<fieldset class="border rounded p-3"><legend class="w-auto px-2 h6">Section naming</legend>';
    html += '<div class="form-group row"><div class="col-md-4"><label class="col-form-label">Pattern</label></div>';
    html += '<div class="col-md-8"><select class="custom-select" data-field="naming-pattern">';
    PRESETS.forEach(p => {
        const sel = (!custom && p.value === state.namingPattern) || (custom && p.value === '__custom__');
        html += `<option value="${p.value}" ${sel ? 'selected' : ''}>${p.label}</option>`;
    });
    html += '</select></div></div>';
    html += `<div class="form-group row ${custom ? '' : 'd-none'}" data-region="custom-pattern">
        <div class="col-md-4"><label class="col-form-label">Custom pattern</label></div>
        <div class="col-md-8">
            <input type="text" class="form-control" data-field="custom-pattern"
                   value="${custom ? state.namingPattern : ''}" placeholder="E.g.: Chapter {N} - {nombre}">
            <small class="form-text text-muted">Use {N} for number and {nombre} for original name</small>
        </div></div>`;
    html += '<div class="form-group row"><div class="col-md-4"><label class="col-form-label">Start from</label></div>';
    html += `<div class="col-md-8"><select class="custom-select" style="width:80px" data-field="naming-start">
        <option value="1" ${state.namingStart === 1 ? 'selected' : ''}>1</option>
        <option value="0" ${state.namingStart === 0 ? 'selected' : ''}>0</option>
    </select></div></div>`;
    html += '<div data-region="naming-preview"></div></fieldset>';

    panel.innerHTML = html;
    updatePreview(panel, state, structure);
    updateHint(panel, state, structure);
    bind(panel, state, structure);
};

/**
 * @param {HTMLElement} p
 * @param {Object} s
 * @param {Array} st
 */
const updatePreview = (p, s, st) => {
    const c = p.querySelector('[data-region="naming-preview"]');
    let h = '<p class="small font-weight-bold text-muted mb-1">Preview:</p>';
    st.forEach((sec, i) => {
        const n = s.namingStart + i;
        h += `<div class="small py-1">${s.namingPattern.replace(/\{N\}/g, n).replace(/\{nombre\}/g, sec.name)}</div>`;
    });
    c.innerHTML = h;
};

/**
 * @param {HTMLElement} p
 * @param {Object} s
 * @param {Array} st
 */
const updateHint = (p, s, st) => {
    const h = p.querySelector('[data-region="sections-hint"]');
    if (s.noLimit) { h.textContent = 'AI can create any number of sections.'; }
    else if (s.maxSections < st.length) { h.textContent = 'AI will merge extra sections to fit the limit.'; }
    else if (s.maxSections > st.length) { h.textContent = 'AI may add additional sections if needed.'; }
    else { h.textContent = 'Same number of sections as the original course.'; }
};

/**
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const bind = (panel, state, structure) => {
    panel.querySelector('[data-field="max-sections"]').addEventListener('change', (e) => {
        state.maxSections = parseInt(e.target.value) || structure.length;
        setState(state); updateHint(panel, state, structure);
    });
    panel.querySelector('[data-field="no-limit"]').addEventListener('change', (e) => {
        state.noLimit = e.target.checked;
        panel.querySelector('[data-field="max-sections"]').disabled = e.target.checked;
        setState(state); updateHint(panel, state, structure);
    });
    panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked && !state.allowedTypes.includes(cb.dataset.type)) {
                state.allowedTypes.push(cb.dataset.type);
            } else { state.allowedTypes = state.allowedTypes.filter(t => t !== cb.dataset.type); }
            setState(state);
        });
    });
    panel.querySelector('[data-action="select-all-types"]').addEventListener('click', () => {
        state.allowedTypes = [...TYPES]; setState(state); renderStepLimits(panel, state);
    });
    panel.querySelector('[data-action="deselect-all-types"]').addEventListener('click', () => {
        state.allowedTypes = []; setState(state); renderStepLimits(panel, state);
    });
    panel.querySelector('[data-field="naming-pattern"]').addEventListener('change', (e) => {
        const cb = panel.querySelector('[data-region="custom-pattern"]');
        if (e.target.value === '__custom__') { cb.classList.remove('d-none'); state.namingPattern = '{nombre}'; }
        else { cb.classList.add('d-none'); state.namingPattern = e.target.value; }
        setState(state); updatePreview(panel, state, structure);
    });
    panel.querySelector('[data-field="custom-pattern"]')?.addEventListener('input', (e) => {
        state.namingPattern = e.target.value || '{nombre}'; setState(state); updatePreview(panel, state, structure);
    });
    panel.querySelector('[data-field="naming-start"]').addEventListener('change', (e) => {
        state.namingStart = parseInt(e.target.value); setState(state); updatePreview(panel, state, structure);
    });
};
