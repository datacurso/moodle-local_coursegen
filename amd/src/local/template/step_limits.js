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
 * Step 5: Generated course limits.
 *
 * @module     local_coursegen/local/template/step_limits
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

/** @type {Array<{id: string, label: string}>} All possible activity types in Moodle. */
const ALL_TYPES = [
    {id: 'forum', label: 'Forum'}, {id: 'assign', label: 'Assignment'},
    {id: 'quiz', label: 'Quiz'}, {id: 'resource', label: 'File'},
    {id: 'lesson', label: 'Lesson'}, {id: 'book', label: 'Book'},
    {id: 'glossary', label: 'Glossary'}, {id: 'workshop', label: 'Workshop'},
    {id: 'url', label: 'URL'}, {id: 'wiki', label: 'Wiki'},
    {id: 'page', label: 'Page'}, {id: 'label', label: 'Label'},
    {id: 'feedback', label: 'Feedback'}, {id: 'choice', label: 'Choice'},
    {id: 'data', label: 'Database'}, {id: 'folder', label: 'Folder'},
    {id: 'h5pactivity', label: 'H5P'}, {id: 'scorm', label: 'SCORM'},
    {id: 'imscp', label: 'IMS'},
];

/** @type {Array<{value: string, label: string}>} Naming pattern presets. */
const NAMING_PRESETS = [
    {value: 'Unidad {N} \u2014 {nombre}', label: 'Unidad {N} \u2014 {nombre}'},
    {value: 'M\u00f3dulo {N}: {nombre}', label: 'M\u00f3dulo {N}: {nombre}'},
    {value: 'Tema {N}: {nombre}', label: 'Tema {N}: {nombre}'},
    {value: 'Semana {N}: {nombre}', label: 'Semana {N}: {nombre}'},
    {value: '{nombre}', label: '{nombre} (name only)'},
    {value: '__custom__', label: 'Custom...'},
];
/**
 * Check whether a pattern string is a custom (non-preset) pattern.
 *
 * @param {string} p
 * @returns {boolean}
 */
const isCustomPattern = (p) => !NAMING_PRESETS.find(q => q.value !== '__custom__' && q.value === p);

/**
 * Render step 5 panel with limit controls.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepLimits = (panel, state) => {
    const structure = state.courseStructure || [];
    const presentTypes = new Set();
    structure.forEach(s => s.activities.forEach(a => presentTypes.add(a.modname)));

    let html = `<h3 class="h5 mb-3">Generated course limits</h3>`;

    // Max sections card.
    html += `<div class="card p-3 mb-3">
        <div class="d-flex align-items-center mb-2">
            <span class="mr-2">&#128208;</span>
            <div>
                <p class="small font-weight-bold mb-0">Maximum sections</p>
                <p class="small text-muted mb-0">Original course has
                    <strong>${structure.length}</strong> sections</p>
            </div>
        </div>
        <div class="d-flex align-items-center">
            <input type="number" class="form-control form-control-sm mr-2" style="width:80px"
                   data-field="max-sections" value="${state.maxSections}" min="1" max="50"
                   ${state.noLimit ? 'disabled' : ''}>
            <span class="small mr-3">sections</span>
            <label class="d-flex align-items-center small mb-0">
                <input type="checkbox" class="mr-1" data-field="no-limit"
                       ${state.noLimit ? 'checked' : ''}>
                No limit
            </label>
        </div>
        <p class="small text-muted mt-2 mb-0" data-region="sections-hint"></p>
    </div>`;

    // Allowed activity types card.
    html += `<div class="card p-3 mb-3">
        <p class="small font-weight-bold mb-2">Allowed activity types</p>
        <p class="small text-muted mb-2">What activity types can AI use in the new course?</p>
        <div class="row">`;
    ALL_TYPES.forEach(t => {
        const isPresent = presentTypes.has(t.id);
        const checked = state.allowedTypes.includes(t.id);
        html += `<div class="col-4 col-md-3 mb-1">
            <label class="d-flex align-items-center small p-1 rounded
                          ${isPresent ? 'tpl-badge-present' : ''}"
                   style="border:1px solid #dee2e6">
                <input type="checkbox" class="mr-1" data-action="toggle-type"
                       data-type="${t.id}" ${checked ? 'checked' : ''}>
                ${t.label}
                ${isPresent ? '<span class="ml-auto small text-muted">&#128204;</span>' : ''}
            </label>
        </div>`;
    });
    html += `</div>
        <div class="mt-2">
            <button class="btn btn-outline-secondary btn-sm mr-1"
                    data-action="select-all-types">Select all</button>
            <button class="btn btn-outline-secondary btn-sm"
                    data-action="deselect-all-types">Deselect all</button>
        </div>
    </div>`;

    // Section naming pattern card.
    const custom = isCustomPattern(state.namingPattern);
    html += `<div class="card p-3">
        <p class="small font-weight-bold mb-2">Section naming pattern</p>
        <select class="custom-select custom-select-sm mb-2" data-field="naming-pattern">`;
    NAMING_PRESETS.forEach(p => {
        const selected = (!custom && p.value === state.namingPattern)
            || (custom && p.value === '__custom__');
        html += `<option value="${p.value}" ${selected ? 'selected' : ''}>${p.label}</option>`;
    });
    html += `</select>
        <div class="mb-2 ${custom ? '' : 'd-none'}" data-region="custom-pattern">
            <input type="text" class="form-control form-control-sm" data-field="custom-pattern"
                   placeholder="E.g.: Chapter {N} \u2014 {nombre}"
                   value="${custom ? state.namingPattern : ''}">
            <p class="small text-muted mt-1 mb-0">
                Use {N} for number and {nombre} for original name
            </p>
        </div>
        <div class="d-flex align-items-center mb-2">
            <span class="small text-muted mr-2">Number from:</span>
            <select class="custom-select custom-select-sm" style="width:auto"
                    data-field="naming-start">
                <option value="1" ${state.namingStart === 1 ? 'selected' : ''}>1</option>
                <option value="0" ${state.namingStart === 0 ? 'selected' : ''}>0</option>
            </select>
        </div>
        <div class="tpl-naming-preview" data-region="naming-preview"></div>
    </div>`;

    panel.innerHTML = html;
    updateNamingPreview(panel, state, structure);
    updateSectionsHint(panel, state, structure);
    bindLimitsEvents(panel, state, structure);
};

/**
 * Update the naming pattern preview section.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const updateNamingPreview = (panel, state, structure) => {
    const container = panel.querySelector('[data-region="naming-preview"]');
    let html = '<p class="small font-weight-bold text-muted mb-1">Preview:</p>';
    structure.forEach((s, i) => {
        const n = state.namingStart + i;
        const rendered = state.namingPattern
            .replace(/\{N\}/g, n)
            .replace(/\{nombre\}/g, s.name);
        html += `<div class="d-flex align-items-center small py-1">
            <i class="icon fa fa-folder-o fa-fw mr-1"></i>${rendered}
        </div>`;
    });
    container.innerHTML = html;
};

/**
 * Update the sections count hint text.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const updateSectionsHint = (panel, state, structure) => {
    const hint = panel.querySelector('[data-region="sections-hint"]');
    if (state.noLimit) {
        hint.textContent = 'AI can create any number of sections.';
    } else if (state.maxSections < structure.length) {
        hint.textContent = 'AI will merge extra sections to fit the limit.';
    } else if (state.maxSections > structure.length) {
        hint.textContent = 'AI may add additional sections if needed.';
    } else {
        hint.textContent = 'Same number of sections as the original course.';
    }
};

/**
 * Bind all interactive event listeners for the limits step.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 * @param {Array} structure
 */
const bindLimitsEvents = (panel, state, structure) => {
    panel.querySelector('[data-field="max-sections"]').addEventListener('change', (e) => {
        state.maxSections = parseInt(e.target.value) || structure.length;
        setState(state);
        updateSectionsHint(panel, state, structure);
    });

    panel.querySelector('[data-field="no-limit"]').addEventListener('change', (e) => {
        state.noLimit = e.target.checked;
        panel.querySelector('[data-field="max-sections"]').disabled = e.target.checked;
        setState(state);
        updateSectionsHint(panel, state, structure);
    });

    panel.querySelectorAll('[data-action="toggle-type"]').forEach(cb => {
        cb.addEventListener('change', () => {
            const type = cb.dataset.type;
            if (cb.checked) {
                if (!state.allowedTypes.includes(type)) {
                    state.allowedTypes.push(type);
                }
            } else {
                state.allowedTypes = state.allowedTypes.filter(t => t !== type);
            }
            setState(state);
        });
    });

    panel.querySelector('[data-action="select-all-types"]').addEventListener('click', () => {
        state.allowedTypes = ALL_TYPES.map(t => t.id);
        setState(state);
        renderStepLimits(panel, state);
    });

    panel.querySelector('[data-action="deselect-all-types"]').addEventListener('click', () => {
        state.allowedTypes = [];
        setState(state);
        renderStepLimits(panel, state);
    });

    panel.querySelector('[data-field="naming-pattern"]').addEventListener('change', (e) => {
        const customBlock = panel.querySelector('[data-region="custom-pattern"]');
        if (e.target.value === '__custom__') {
            customBlock.classList.remove('d-none');
            state.namingPattern = panel.querySelector('[data-field="custom-pattern"]').value
                || '{nombre}';
        } else {
            customBlock.classList.add('d-none');
            state.namingPattern = e.target.value;
        }
        setState(state);
        updateNamingPreview(panel, state, structure);
    });

    const customInput = panel.querySelector('[data-field="custom-pattern"]');
    if (customInput) {
        customInput.addEventListener('input', (e) => {
            state.namingPattern = e.target.value || '{nombre}';
            setState(state);
            updateNamingPreview(panel, state, structure);
        });
    }

    panel.querySelector('[data-field="naming-start"]').addEventListener('change', (e) => {
        state.namingStart = parseInt(e.target.value);
        setState(state);
        updateNamingPreview(panel, state, structure);
    });
};

// Re-export alias for the init module to call directly.
export {renderStepLimits as render};
