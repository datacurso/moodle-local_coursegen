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
 * Generated-course limits (allow-add-sections / extra sections) — binds state-tracking events on
 * the real mform elements rendered by classes/form/template_config_form.php
 * (a \core_form\dynamic_form, reloaded via core_form/dynamicform every time
 * the selected course changes — see init.js).
 *
 * The "show the extra-sections field only while allow-add-sections is
 * checked" behavior is the form's own disabledIf() rule (see
 * template_config_form::definition()) and needs no JS at all. This module
 * only tracks state. The naming pattern belongs to each template section
 * (see local_coursegen/local/template/section_naming).
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

import Selectors from 'local_coursegen/local/template/selectors';
import {EVENT} from 'local_coursegen/local/template/constants';

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
 * Track the extra sections allowance when the form changes it.
 *
 * @param {Object} ctx The limits context (see buildContext()).
 */
const handleMaxSectionsChange = (ctx) => {
    ctx.state.maxSections = readMaxSections(ctx);
};

/**
 * Everything the limits handlers need: the rendered fields and the state.
 *
 * @param {HTMLElement} panel The config region (config-form markup).
 * @param {Object} state
 * @returns {Object}
 */
const buildContext = (panel, state) => {
    const structure = state.courseStructure;
    const maxInput = panel.querySelector(Selectors.regions.maxSections);
    // advcheckbox renders a hidden "unchecked" companion input sharing the
    // same name before the real checkbox — [type="checkbox"] is required to
    // land on the actual toggle, not its always-present hidden sibling.
    const allowAddCb = panel.querySelector(Selectors.regions.allowAddSections);
    return {panel, state, structure, maxInput, allowAddCb};
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
 * Bind events on the rendered limits form.
 *
 * @param {HTMLElement} panel The config region (config-form markup).
 * @param {Object} state
 * @returns {Promise}
 */
export const renderStepLimits = async(panel, state) => {
    const ctx = buildContext(panel, state);
    bindMaxSections(ctx);
};
