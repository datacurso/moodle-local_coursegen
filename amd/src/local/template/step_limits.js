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
 * Generated-course limits (max sections / no limit / allowed activity
 * types) — binds state-tracking events on the real mform elements rendered
 * by classes/form/template_config_form.php.
 *
 * The "disable the max-sections field while no-limit is checked" behavior
 * used to be hand-wired here; it is now the form's own disabledIf() rule
 * (see template_config_form::definition()) and needs no JS at all.
 *
 * @module     local_coursegen/local/template/step_limits
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let bound = false;

/**
 * Bind events on the server-rendered limits form.
 *
 * @param {HTMLElement} panel Element containing the rendered config form.
 * @param {Object} state
 */
export const renderStepLimits = (panel, state) => {
    if (bound) {
        return;
    }
    bound = true;

    const structure = state.courseStructure || [];

    const maxInput = panel.querySelector('#id_maxsections');
    const noLimitCb = panel.querySelector('#id_nolimit');
    if (maxInput) {
        state.maxSections = parseInt(maxInput.value, 10) || structure.length;
    }
    if (noLimitCb) {
        state.noLimit = noLimitCb.checked;
    }

    // Allowed types: one real advcheckbox per installed activity type
    // (name="allowedtype_<modname>"), already pre-checked server-side for
    // whichever types the selected course actually uses.
    state.allowedTypes = [];
    panel.querySelectorAll('input[name^="allowedtype_"]').forEach(cb => {
        const modname = cb.name.replace('allowedtype_', '');
        if (cb.checked) {
            state.allowedTypes.push(modname);
        }
        cb.addEventListener('change', () => {
            if (cb.checked && !state.allowedTypes.includes(modname)) {
                state.allowedTypes.push(modname);
            } else if (!cb.checked) {
                state.allowedTypes = state.allowedTypes.filter(t => t !== modname);
            }
        });
    });

    maxInput?.addEventListener('change', () => {
        state.maxSections = parseInt(maxInput.value, 10) || structure.length;
    });

    noLimitCb?.addEventListener('change', () => {
        state.noLimit = noLimitCb.checked;
    });

    // Section naming — not yet converted to the form API (out of scope for
    // this pass), still driven by plain data-field markup.
    panel.querySelector('[data-field="naming-pattern"]')?.addEventListener('change', (e) => {
        const customBlock = panel.querySelector('[data-region="custom-pattern"]');
        if (e.target.value === '__custom__') {
            customBlock.classList.remove('d-none');
            state.namingPattern = panel.querySelector('[data-field="custom-pattern"]')?.value || '{nombre}';
        } else {
            customBlock.classList.add('d-none');
            state.namingPattern = e.target.value;
        }
        updatePreview(panel, state, structure);
    });

    panel.querySelector('[data-field="custom-pattern"]')?.addEventListener('input', (e) => {
        state.namingPattern = e.target.value || '{nombre}';
        updatePreview(panel, state, structure);
    });

    panel.querySelector('[data-field="naming-start"]')?.addEventListener('change', (e) => {
        state.namingStart = parseInt(e.target.value, 10);
        updatePreview(panel, state, structure);
    });

    updatePreview(panel, state, structure);
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
