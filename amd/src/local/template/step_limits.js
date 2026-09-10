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
 * types / section naming pattern) — binds state-tracking events on the real
 * mform elements rendered by classes/form/template_config_form.php (a
 * \core_form\dynamic_form, reloaded via core_form/dynamicform every time the
 * selected course changes — see init.js).
 *
 * The "disable the max-sections field while no-limit is checked" behavior,
 * and the "show the custom-pattern field only when the pattern select is set
 * to Custom" behavior, both used to be hand-wired here; they are now the
 * form's own disabledIf()/hideIf() rules (see
 * template_config_form::definition()) and need no JS at all. This module
 * only tracks state and re-renders the live naming preview, which stays
 * client-side JS on purpose — it reads state.courseStructure (already
 * loaded separately) rather than round-tripping to the server on every
 * keystroke.
 *
 * Selectors here are all by NAME, never by id: dynamic_form forces
 * data-random-ids on its rendered elements (so several dynamic forms can
 * coexist on one page without id collisions), so #id_maxsections/#id_nolimit
 * do not reliably exist — the field's `name` attribute is the only stable
 * handle. Binds fresh on every call (no "already bound" guard): the config
 * form's markup is fully replaced on every reload, so there is never a
 * stale listener to avoid re-adding.
 *
 * @module     local_coursegen/local/template/step_limits
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bind events on the rendered limits form.
 *
 * @param {HTMLElement} panel The config region (config-form markup).
 * @param {Object} state
 */
export const renderStepLimits = (panel, state) => {
    const structure = state.courseStructure || [];

    const maxInput = panel.querySelector('[name="maxsections"]');
    // advcheckbox renders a hidden "unchecked" companion input sharing the
    // same name before the real checkbox — [type="checkbox"] is required to
    // land on the actual toggle, not its always-present hidden sibling.
    const noLimitCb = panel.querySelector('input[type="checkbox"][name="nolimit"]');
    if (maxInput) {
        state.maxSections = parseInt(maxInput.value, 10) || structure.length;
    }
    if (noLimitCb) {
        state.noLimit = noLimitCb.checked;
    }

    // Allowed types: a single mform autocomplete multi-select
    // (name="allowedtypes[]", real <select multiple> under the hood),
    // already pre-selected server-side for whichever types the selected
    // course actually uses — replaces what used to be one advcheckbox per
    // installed activity type.
    const allowedSelect = panel.querySelector('select[name="allowedtypes[]"]');
    const readAllowedTypes = () => {
        if (!allowedSelect) {
            return [];
        }
        const selected = [];
        for (const option of allowedSelect.selectedOptions) {
            selected.push(option.value);
        }
        return selected;
    };
    state.allowedTypes = readAllowedTypes();
    allowedSelect?.addEventListener('change', () => {
        state.allowedTypes = readAllowedTypes();
    });

    maxInput?.addEventListener('change', () => {
        state.maxSections = parseInt(maxInput.value, 10) || structure.length;
    });

    noLimitCb?.addEventListener('change', () => {
        state.noLimit = noLimitCb.checked;
    });

    // Section naming — real mform elements now (select[name="namingpattern"],
    // text[name="custompattern"], select[name="namingstart"], see
    // classes/form/template_config_form.php::definition_naming_pattern()).
    // The custom-pattern field's own show/hide is the form's native hideIf()
    // rule — nothing to do here for that — this only tracks state.
    const patternSelect = panel.querySelector('select[name="namingpattern"]');
    const customInput = panel.querySelector('input[name="custompattern"]');

    const readNamingPattern = () => {
        if (patternSelect?.value === '__custom__') {
            return customInput?.value || '{nombre}';
        }
        return patternSelect?.value || state.namingPattern;
    };

    if (patternSelect) {
        state.namingPattern = readNamingPattern();
    }

    patternSelect?.addEventListener('change', () => {
        state.namingPattern = readNamingPattern();
        updatePreview(panel, state, structure);
    });

    customInput?.addEventListener('input', () => {
        state.namingPattern = readNamingPattern();
        updatePreview(panel, state, structure);
    });

    const startSelect = panel.querySelector('select[name="namingstart"]');
    if (startSelect) {
        state.namingStart = parseInt(startSelect.value, 10);
    }
    startSelect?.addEventListener('change', (e) => {
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
