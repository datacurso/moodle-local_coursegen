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
 * Generated-course limits (allow-add-sections / extra sections / allowed
 * activity types / section naming pattern) — binds state-tracking events on
 * the real mform elements rendered by classes/form/template_config_form.php
 * (a \core_form\dynamic_form, reloaded via core_form/dynamicform every time
 * the selected course changes — see init.js).
 *
 * The "show the extra-sections field only while allow-add-sections is
 * checked" behavior, and the "show the custom-pattern field only when the
 * pattern select is set to Custom" behavior, are the form's own
 * disabledIf()/hideIf() rules (see
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

import {get_string as getString} from 'core/str';
import Templates from 'core/templates';

/**
 * The "no activity types selected" string core/form-autocomplete shows
 * inside the chip area when nothing is selected — fetched once and reused,
 * since the select-all/none buttons need to reproduce that exact placeholder
 * themselves (see renderAllowedTypesChips below).
 *
 * @type {Promise<string>|null}
 */
let noSelectionStringPromise = null;
const getNoSelectionString = () => {
    if (!noSelectionStringPromise) {
        noSelectionStringPromise = getString('template_allowed_types_none', 'local_coursegen');
    }
    return noSelectionStringPromise;
};

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
    const allowAddCb = panel.querySelector('input[type="checkbox"][name="allowaddsections"]');
    // maxsections is the number of EXTRA sections the teacher may add on top
    // of the template's own — 0 (no extra sections) unless the
    // allow-add-sections checkbox is ticked. nolimit is never set from this
    // UI any more; it stays false in state and is only kept in the save
    // payload for backward compatibility with existing rows.
    const readMaxSections = () => {
        if (!allowAddCb?.checked) {
            return 0;
        }
        return parseInt(maxInput?.value, 10) || 0;
    };
    if (maxInput || allowAddCb) {
        state.maxSections = readMaxSections();
        state.noLimit = false;
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

    // "Select all" / "Select none" (see template_config_form.php) act on
    // the same underlying <select> a normal search-and-click would, so the
    // change listener above still picks up the result the same way. The
    // widget core/form-autocomplete builds only re-renders the visible chip
    // area through its OWN click handlers, though — it never watches the
    // <select> for changes made any other way — so these buttons also have
    // to redraw the chip area themselves, reproducing the exact markup
    // core/form_autocomplete_selection_items.mustache renders (a chip per
    // selected option, or the "nothing selected" placeholder) so it stays
    // indistinguishable from what a normal click would have produced.
    const allowedChips = allowedSelect?.parentElement?.querySelector('.form-autocomplete-selection');

    const renderAllowedTypesChips = async() => {
        if (!allowedSelect || !allowedChips) {
            return;
        }

        const items = [];
        for (const option of allowedSelect.options) {
            if (option.selected) {
                items.push({label: option.textContent, value: option.value});
            }
        }

        // Rendered through Moodle's own template, not hand-built markup:
        // core/form-autocomplete's own click handlers (e.g. removing a chip)
        // read specific child nodes of each chip by position, which only
        // matches if the chip's HTML is exactly what this template produces.
        const noneText = await getNoSelectionString();
        const {html, js} = await Templates.renderForPromise('core/form_autocomplete_selection_items', {
            items,
            noSelectionString: noneText,
        });
        Templates.replaceNodeContents(allowedChips, html, js);
    };

    const setAllAllowedTypes = async(selected) => {
        if (!allowedSelect) {
            return;
        }
        for (const option of allowedSelect.options) {
            option.selected = selected;
        }
        await renderAllowedTypesChips();
        allowedSelect.dispatchEvent(new Event('change', {bubbles: true}));
    };

    panel.querySelector('[data-action="select-all-allowedtypes"]')?.addEventListener('click', () => {
        setAllAllowedTypes(true);
    });
    panel.querySelector('[data-action="select-none-allowedtypes"]')?.addEventListener('click', () => {
        setAllAllowedTypes(false);
    });

    maxInput?.addEventListener('change', () => {
        state.maxSections = readMaxSections();
    });

    allowAddCb?.addEventListener('change', () => {
        state.maxSections = readMaxSections();
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
