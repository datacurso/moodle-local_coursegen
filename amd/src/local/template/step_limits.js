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
 * Generated-course limits (allow-add-sections / extra sections / section
 * naming pattern) — binds state-tracking events on
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

import Templates from 'core/templates';
import {EVENT} from 'local_coursegen/local/template/constants';
import {SELECTOR} from 'local_coursegen/local/template/dom_constants';

/**
 * Add a listener to an element, when the form rendered it.
 *
 * @param {HTMLElement|null} element
 * @param {string} type The event type.
 * @param {Function} handler
 */
const listen = (element, type, handler) => {
    if (!element) {
        return;
    }
    element.addEventListener(type, handler);
};

/**
 * The extra sections the teacher may add, read from the form.
 *
 * maxsections is the number of EXTRA sections the teacher may add on top of
 * the template's own — 0 (no extra sections) unless the allow-add-sections
 * checkbox is ticked.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 * @returns {number}
 */
const readMaxSections = (ctx) => {
    if (!ctx.allowAddCb?.checked) {
        return 0;
    }
    return parseInt(ctx.maxInput?.value, 10) || 0;
};

/**
 * The naming pattern the form currently describes.
 *
 * The naming fields are always rendered once a course is selected (see
 * template_config_form::definition_naming_pattern()), and the select always
 * has a selected option, so neither needs a guard. The custom field is typed
 * by the teacher: when it is empty the pattern is the section's name alone.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 * @returns {string}
 */
const readNamingPattern = (ctx) => {
    const contract = ctx.state.namingContract;
    const selected = ctx.patternSelect.value;
    if (selected !== contract.customvalue) {
        return selected;
    }
    return ctx.customInput.value || contract.nametoken;
};

/**
 * A naming pattern with its tokens replaced by a section's number and name.
 *
 * Tokens are replaced as plain text, so the section's name never acts as a
 * replacement pattern.
 *
 * @param {string} pattern The naming pattern.
 * @param {Object} contract The tokens of the pattern, from the server.
 * @param {number} number The section's number.
 * @param {string} sectionName The section's name.
 * @returns {string}
 */
const applyTokens = (pattern, contract, number, sectionName) => {
    const numberParts = pattern.split(contract.numbertoken);
    const numbered = numberParts.join(number);
    const nameParts = numbered.split(contract.nametoken);
    return nameParts.join(sectionName);
};

/**
 * The preview line of one section, as the template's context expects it.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 * @param {string} sectionName
 * @param {number} index The section's position.
 * @returns {{text: string}}
 */
const buildPreviewLine = (ctx, sectionName, index) => {
    const number = ctx.state.namingStart + index;
    const text = applyTokens(ctx.state.namingPattern, ctx.state.namingContract, number, sectionName);
    return {text};
};

/**
 * Update the naming preview.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const updatePreview = async(ctx) => {
    const container = ctx.panel.querySelector(SELECTOR.NAMING_PREVIEW);
    const lines = [];
    let index = 0;
    for (const section of ctx.structure) {
        const line = buildPreviewLine(ctx, section.name, index);
        lines.push(line);
        index++;
    }
    const rendered = await Templates.render('local_coursegen/template_naming_preview', {lines});
    Templates.replaceNodeContents(container, rendered, '');
};

/**
 * Track the extra sections allowance when the form changes it.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const handleMaxSectionsChange = (ctx) => {
    ctx.state.maxSections = readMaxSections(ctx);
};

/**
 * Track the naming pattern when the form changes it, and refresh the preview.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const handleNamingPatternChange = (ctx) => {
    ctx.state.namingPattern = readNamingPattern(ctx);
    updatePreview(ctx);
};

/**
 * Track the first section number when the form changes it, and refresh the preview.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 * @param {Event} e The change event.
 */
const handleStartChange = (ctx, e) => {
    ctx.state.namingStart = parseInt(e.target.value, 10);
    updatePreview(ctx);
};

/**
 * Everything the limits handlers need: the rendered fields and the state.
 *
 * Section naming — real mform elements (select[name="namingpattern"],
 * text[name="custompattern"], select[name="namingstart"], see
 * classes/form/template_config_form.php::definition_naming_pattern()).
 * The custom-pattern field's own show/hide is the form's native hideIf()
 * rule — nothing to do here for that — this only tracks state.
 *
 * @param {HTMLElement} panel The config region (config-form markup).
 * @param {Object} state
 * @returns {Object}
 */
const buildContext = (panel, state) => {
    const structure = state.courseStructure;
    const maxInput = panel.querySelector('[name="maxsections"]');
    // advcheckbox renders a hidden "unchecked" companion input sharing the
    // same name before the real checkbox — [type="checkbox"] is required to
    // land on the actual toggle, not its always-present hidden sibling.
    const allowAddCb = panel.querySelector('input[type="checkbox"][name="allowaddsections"]');
    const patternSelect = panel.querySelector('select[name="namingpattern"]');
    const customInput = panel.querySelector('input[name="custompattern"]');
    const startSelect = panel.querySelector('select[name="namingstart"]');
    return {panel, state, structure, maxInput, allowAddCb, patternSelect, customInput, startSelect};
};

/**
 * Seed the extra sections allowance from the rendered form and track changes.
 * nolimit is never set from this UI any more; it stays false in state and is
 * only kept in the save payload for backward compatibility with existing rows.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const bindMaxSections = (ctx) => {
    if (ctx.maxInput || ctx.allowAddCb) {
        ctx.state.maxSections = readMaxSections(ctx);
        ctx.state.noLimit = false;
    }
    const onChange = handleMaxSectionsChange.bind(null, ctx);
    listen(ctx.maxInput, EVENT.CHANGE, onChange);
    listen(ctx.allowAddCb, EVENT.CHANGE, onChange);
};

/**
 * Seed the naming pattern from the rendered form and track changes.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const bindNamingPattern = (ctx) => {
    ctx.state.namingPattern = readNamingPattern(ctx);
    const onChange = handleNamingPatternChange.bind(null, ctx);
    ctx.patternSelect.addEventListener(EVENT.CHANGE, onChange);
    ctx.customInput.addEventListener(EVENT.INPUT, onChange);
};

/**
 * Seed the first section number from the rendered form and track changes.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const bindNamingStart = (ctx) => {
    ctx.state.namingStart = parseInt(ctx.startSelect.value, 10);
    const onChange = handleStartChange.bind(null, ctx);
    ctx.startSelect.addEventListener(EVENT.CHANGE, onChange);
};

/**
 * Bind events on the rendered limits form.
 *
 * @param {HTMLElement} panel The config region (config-form markup).
 * @param {Object} state
 * @returns {Promise}
 */
export const renderStepLimits = async(panel, state) => {
    const ctx = buildContext(panel, state);
    bindMaxSections(ctx);
    bindNamingPattern(ctx);
    bindNamingStart(ctx);
    updatePreview(ctx);
};
