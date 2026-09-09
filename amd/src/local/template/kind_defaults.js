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
 * has ~28 activities). The admin sets ONE behavior per kind (a real
 * mform 'select' element, see classes/form/template_config_form.php), and
 * this module bulk-applies that choice to every activity of the kind and
 * syncs their individual dropdowns, so the admin only has to touch the rare
 * exception via the per-activity dropdown that already exists in the
 * section/activity review below.
 *
 * This module used to also BUILD the <select> markup itself; that moved
 * into template_config_form.php (real mform elements, server-rendered) —
 * this module now only binds behavior on top of what the form already
 * rendered.
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
 * since a JS module cannot read a PHP class constant directly. The PHP side
 * (template_config_form) is the single source of truth for which options
 * the kind-default <select> itself offers; this copy only still matters for
 * seeding each activity's initial per-activity action as soon as a course's
 * structure loads, before the config form has necessarily rendered yet
 * (see applyKindDefaultsToState, called from init.js::initSectionState).
 *
 * @type {string[]}
 */
const AI_SUPPORTED = ['page', 'label', 'forum', 'assign'];

/**
 * Sensible default action per recognised component kind — mirrors
 * template_config_form::KIND_META (PHP).
 *
 * @type {Object<string, string>}
 */
const DEFAULT_ACTION = {
    label: 'modify',
    page: 'modify',
    forum: 'keep',
    resource: 'keep',
    assign: 'modify',
    feedback: 'keep',
    lesson: 'keep',
};

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
    const wanted = DEFAULT_ACTION[modname] || 'keep';
    // Never default a kind the generator can't handle to "modify" — even if
    // DEFAULT_ACTION said so, an unsupported kind must default to "keep".
    return (wanted === 'modify' && !kindSupportsModify(modname)) ? 'keep' : wanted;
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
 * Bind the kind-default <select> elements the form already rendered
 * (name="kinddefault_<modname>", one per kind present in the course — see
 * classes/form/template_config_form.php). Changing one bulk-applies that
 * action to every activity of that kind and refreshes their individual
 * dropdowns/prompts so the two controls never disagree.
 *
 * @param {HTMLElement} formContainer Element containing the rendered config form.
 * @param {HTMLElement} structureContainer The rendered course structure (holds the per-activity controls to sync).
 * @param {Object} state
 */
export const bindKindDefaults = (formContainer, structureContainer, state) => {
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

    formContainer.querySelectorAll('select[name^="kinddefault_"]').forEach(select => {
        const modname = select.name.replace('kinddefault_', '');
        const cmids = present.get(modname) || [];

        select.addEventListener('change', () => {
            const value = select.value;
            cmids.forEach(cmid => {
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
