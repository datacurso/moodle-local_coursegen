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
 * Default behavior per KIND of component, instead of per individual activity.
 *
 * A real template repeats the same handful of component kinds over and over
 * (a welcome/section banner, an informational page, a discussion forum, a
 * file attachment, a graded activity, a closing survey, a multi-page
 * lesson) — dozens of times across a real course. Reviewing every single
 * activity one by one does not scale (a real template reviewed this session
 * has ~28 activities). This module lets the admin set ONE behavior per kind,
 * applied to every activity of that kind at once, and only touch the rare
 * exception via the per-activity dropdown that already exists in the
 * section/activity review below.
 *
 * @module     local_coursegen/local/template/kind_defaults
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

/**
 * Module names the AI generator can actually produce content for today.
 *
 * Mirrors mock_template_ai_service::SUPPORTED (PHP) — kept in sync manually
 * since a JS module cannot read a PHP class constant directly; the PHP side
 * is still the single source of truth enforced server-side (see
 * classes/output/sections_config.php), this list only drives which default
 * this client-side panel is allowed to offer/pre-select.
 *
 * @type {string[]}
 */
const AI_SUPPORTED = ['page', 'label', 'forum', 'assign'];

/**
 * Friendly label and sensible default action per recognised component kind.
 *
 * The default reflects how each kind is actually used in real templates
 * reviewed for this feature: banners and informational pages are almost
 * always regenerated per course; forums, file attachments and the closing
 * survey rarely change and are kept as-is by default; graded activities are
 * regenerated but keep their grading setup; lesson content defaults to
 * "keep" only because the generator cannot produce it yet (see the
 * "Support every activity type real templates actually use" phase) — once
 * it can, this default should become "modify" like the other content kinds.
 *
 * @type {Object<string, {label: string, defaultAction: string}>}
 */
const KIND_META = {
    label: {label: 'Banners', defaultAction: 'modify'},
    page: {label: 'Informational pages', defaultAction: 'modify'},
    forum: {label: 'Discussion forums', defaultAction: 'keep'},
    resource: {label: 'File attachments', defaultAction: 'keep'},
    assign: {label: 'Graded activities', defaultAction: 'modify'},
    feedback: {label: 'Closing survey', defaultAction: 'keep'},
    lesson: {label: 'Lesson content', defaultAction: 'keep'},
};

/** Fallback for a module type not in KIND_META (unusual, but must not break). */
const FALLBACK_META = {label: null, defaultAction: 'keep'};

/**
 * @param {string} modname
 * @returns {boolean} Whether "Modify" is a safe option for this module type today.
 */
export const kindSupportsModify = (modname) => AI_SUPPORTED.includes(modname);

/**
 * @param {string} modname
 * @returns {string} The sensible default action for this module type.
 */
export const defaultActionForModname = (modname) => {
    const meta = KIND_META[modname] || FALLBACK_META;
    // Never default a kind the generator can't handle to "modify" — even if
    // KIND_META said so, an unsupported kind must default to "keep".
    return (meta.defaultAction === 'modify' && !kindSupportsModify(modname)) ? 'keep' : meta.defaultAction;
};

/**
 * Seed state.activityAction with the per-kind default for every activity
 * that doesn't already have an explicit value — never overwrites a value
 * the admin (or a previous render) already set.
 *
 * @param {HTMLElement} container The rendered course structure, to read real modnames from.
 * @param {Object} state
 */
export const applyKindDefaultsToState = (container, state) => {
    container.querySelectorAll('[data-for="cmitem"][data-modname]').forEach(cmitem => {
        const cmid = parseInt(cmitem.dataset.id);
        const modname = cmitem.dataset.modname;
        if (!cmid || state.activityAction[cmid] !== undefined) {
            return;
        }
        state.activityAction[cmid] = defaultActionForModname(modname);
        state.activityRef[cmid] = true;
    });
};

/**
 * Render the "defaults by kind" panel: one row per kind actually present in
 * this course, with one select each. Changing a row bulk-applies that
 * action to every activity of that kind and refreshes their individual
 * dropdowns/prompts so the two controls never disagree.
 *
 * @param {HTMLElement} panel Target element for the panel's markup.
 * @param {HTMLElement} structureContainer The rendered course structure (holds the per-activity controls to sync).
 * @param {Object} state
 */
export const renderKindDefaults = (panel, structureContainer, state) => {
    const present = new Map(); // modname -> [cmid, ...]
    structureContainer.querySelectorAll('[data-for="cmitem"][data-modname]').forEach(cmitem => {
        const cmid = parseInt(cmitem.dataset.id);
        const modname = cmitem.dataset.modname;
        if (!cmid) {
            return;
        }
        if (!present.has(modname)) {
            present.set(modname, []);
        }
        present.get(modname).push(cmid);
    });

    if (present.size === 0) {
        panel.innerHTML = '';
        return;
    }

    const rows = [...present.keys()].sort().map(modname => {
        const meta = KIND_META[modname] || FALLBACK_META;
        const label = meta.label || modname;
        const cmids = present.get(modname);
        const current = state.activityAction[cmids[0]] || defaultActionForModname(modname);
        const canModify = kindSupportsModify(modname);
        const options = ['keep', 'reference', 'exclude'];
        if (canModify) {
            options.unshift('modify');
        }
        const optionLabels = {modify: 'Modify', keep: 'Keep', reference: 'Reference', exclude: 'Exclude'};
        const opts = options.map(opt =>
            `<option value="${opt}" ${opt === current ? 'selected' : ''}>${optionLabels[opt]}</option>`
        ).join('');
        return `<div class="col-md-4 col-lg-3 mb-2">
            <label class="d-block small font-weight-bold mb-1">${label}
                <span class="text-muted font-weight-normal">(${cmids.length})</span>
            </label>
            <select class="custom-select custom-select-sm" data-kind-default="${modname}">${opts}</select>
        </div>`;
    }).join('');

    panel.innerHTML = `<div class="row">${rows}</div>`;

    panel.querySelectorAll('[data-kind-default]').forEach(select => {
        select.addEventListener('change', () => {
            const modname = select.dataset.kindDefault;
            const value = select.value;
            present.get(modname).forEach(cmid => {
                state.activityAction[cmid] = value;
                if (value === 'modify') {
                    state.activityRef[cmid] = true;
                }
                syncActivityControl(structureContainer, cmid, value);
            });
            setState(state);
        });
    });
};

/**
 * Keep one activity's own dropdown/prompt in sync after a bulk kind-default change.
 *
 * @param {HTMLElement} structureContainer
 * @param {number} cmid
 * @param {string} value
 */
const syncActivityControl = (structureContainer, cmid, value) => {
    const cmitem = structureContainer.querySelector(`[data-for="cmitem"][data-id="${cmid}"]`);
    if (!cmitem) {
        return;
    }
    const labels = {modify: 'Modify', keep: 'Keep', reference: 'Reference', exclude: 'Exclude'};
    const colors = {modify: '#0f6cbf', keep: '#28a745', reference: '#6f42c1', exclude: '#6c757d'};
    const btn = cmitem.querySelector('[data-tpl-control] .dropdown-toggle');
    if (btn) {
        btn.textContent = labels[value];
        btn.style.color = colors[value];
    }
    cmitem.querySelectorAll('[data-act-val]').forEach(item => {
        item.classList.toggle('active', item.dataset.actVal === value);
    });
    cmitem.style.opacity = value === 'exclude' ? '0.35' : '1';
    const promptwrap = cmitem.querySelector(`[data-tpl-prompt-wrap="${cmid}"]`);
    if (promptwrap) {
        promptwrap.style.display = value === 'modify' ? '' : 'none';
    }
};
