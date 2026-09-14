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
 * Event bindings for "Add activity from a template": the hover-reveal gap
 * triggers, the section's persistent trigger, and each instance row's
 * prompt-toggle/remove icons. Bound the same way sections_events.js binds
 * everything else — again after every render (initial page load and each
 * AJAX course switch).
 *
 * Which templates are offered for a given section is computed entirely
 * client-side, from the rows already on the page plus state.activityScope —
 * an unsaved "Use as template" row picked earlier in this same editing
 * session is immediately offerable, with no server round trip.
 *
 * @module     local_coursegen/local/template/template_instance_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openInstanceMenu, closeInstanceMenu, beginMenuOpen} from './template_instance_menu';
import {insertInstanceRow, removeInstanceRow} from './template_instance_rows';
import {getStrings} from 'core/str';
import Notification from 'core/notification';
import {prefetchStrings} from 'core/prefetch';

/**
 * Build one marked row's own picker option, or null if the row is missing
 * something it needs (no cmid, or no section ancestor to check eligibility
 * against) — a single malformed row must never take the whole list down.
 *
 * @param {HTMLElement} row A row carrying the "tpl-row-template" class.
 * @param {number} targetsectionid The section the "+" was triggered from.
 * @param {Object} state The live wizard state from init.js.
 * @param {Object} hints {samesectionhint, coursehint, tooltip} pre-fetched strings.
 * @returns {Object|null}
 */
const buildOneOption = (row, targetsectionid, state, hints) => {
    const cmid = parseInt(row.dataset.id, 10);
    const sectionEl = row.closest('[data-for="section"]');
    if (!cmid || !sectionEl) {
        return null;
    }
    const sectionid = parseInt(sectionEl.dataset.id, 10);
    const scope = state.activityScope[cmid] || 'course';
    const eligible = scope === 'course' || sectionid === targetsectionid;

    let scopehint = hints.samesectionhint;
    let itemtooltip = '';
    if (eligible) {
        if (scope === 'course') {
            scopehint = hints.coursehint;
        }
    } else {
        itemtooltip = hints.tooltip;
    }

    return {
        sourcecmid: cmid,
        name: row.querySelector('.tpl-template-tag')?.dataset.name || '',
        typelabel: row.dataset.typelabel || '',
        modname: row.dataset.modname || '',
        iconurl: row.querySelector('img.activityicon')?.src || '',
        disabled: !eligible,
        scopehint,
        tooltip: itemtooltip,
    };
};

/**
 * Build the picker's option list for one target section: every row
 * currently marked "Use as template" anywhere in the review, enabled when
 * its scope makes it eligible for this section.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {number} targetsectionid The section the "+" was triggered from.
 * @param {Object} state The live wizard state from init.js.
 * @returns {Promise<Array>} Menu options (see template_instance_menu.mustache).
 */
const buildAvailableTemplates = async(container, targetsectionid, state) => {
    const [samesectionhint, coursehint, tooltip] = await getStrings([
        {key: 'template_instance_scope_same_section', component: 'local_coursegen'},
        {key: 'template_instance_scope_whole_course', component: 'local_coursegen'},
        {key: 'template_instance_scope_unavailable', component: 'local_coursegen'},
    ]);
    const hints = {samesectionhint, coursehint, tooltip};

    const templaterows = container.querySelectorAll('.tpl-row-template[data-for="cmitem"]');
    const options = [];
    for (const row of templaterows) {
        const option = buildOneOption(row, targetsectionid, state, hints);
        if (option !== null) {
            options.push(option);
        }
    }
    return options;
};

/**
 * Toggle one instance row's prompt drawer open/closed.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {string} instanceid
 */
const togglePromptDrawer = (container, instanceid) => {
    const drawer = container.querySelector(
        '[data-for="instanceprompt"][data-instance-id="' + instanceid + '"]'
    );
    drawer?.classList.toggle('d-none');
};

/**
 * Resolve a trigger's target section, fetch the available templates for it,
 * and open its picker.
 *
 * Claims this open's token synchronously, before any of the async work
 * below starts — see template_instance_menu.js's latestOpenToken doc for
 * why: it is what makes a later click on this same trigger win over an
 * earlier one whose own async work happens to settle later.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {HTMLElement} trigger The clicked "+" button.
 * @param {Object} state The live wizard state from init.js.
 */
const openMenuForTrigger = async(container, trigger, state) => {
    const token = beginMenuOpen(trigger);
    const sectionEl = trigger.closest('[data-for="section"]');
    const sectionid = parseInt(sectionEl.dataset.id, 10);
    const options = await buildAvailableTemplates(container, sectionid, state);
    await openInstanceMenu({triggerEl: trigger, options, token});
};

/**
 * Handle a click on one enabled picker item: insert its instance row
 * immediately before the row-gap/add-instance row the picker opened from,
 * then close that dropdown explicitly rather than trust the click to keep
 * bubbling into Bootstrap's own outside-click handler.
 *
 * @param {HTMLElement} item The clicked picker item (data-source-cmid).
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
const pickTemplate = (item, markDirty) => {
    const beforeEl = item.closest('[data-region="row-gap"], [data-region="add-instance"]');
    const tbody = beforeEl.closest('table').querySelector('tbody');
    const triggerEl = beforeEl.querySelector('[data-instance-menu-trigger]');
    const picked = {
        sourcecmid: parseInt(item.dataset.sourceCmid, 10),
        sourcename: item.dataset.sourceName,
        typelabel: item.dataset.typeLabel,
        modname: item.dataset.modname,
        iconurl: item.dataset.iconUrl,
    };
    closeInstanceMenu(triggerEl);
    insertInstanceRow(tbody, beforeEl, picked).then(markDirty);
};

/**
 * Bind everything under "Add activity from a template": gap/persistent
 * triggers open the picker, an accepted pick inserts a new instance row,
 * and each instance row's own icons work (prompt toggle, remove).
 *
 * Warms the string cache for every getStrings() call this feature can
 * trigger — buildAvailableTemplates() above and insertInstanceRow() in
 * template_instance_rows.js, which has no entry point of its own — so the
 * admin's first "+" click resolves from cache instead of a network round
 * trip.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindInstanceInserts = (container, state, markDirty) => {
    prefetchStrings('local_coursegen', [
        'template_instance_scope_same_section',
        'template_instance_scope_whole_course',
        'template_instance_scope_unavailable',
        'template_add_instance',
        'template_instance_badge',
        'template_instance_name',
        'template_instance_prompt_edit',
        'template_instance_remove',
        'template_instance_prompt_placeholder',
    ]);

    container.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-instance-menu-trigger]');
        if (trigger) {
            // Bound on the CAPTURE phase (see addEventListener's 3rd argument
            // below), not bubble: openMenuForTrigger()'s own dropdown('toggle')
            // call lazily instantiates Bootstrap's per-element Dropdown the
            // first time it runs (theme/boost/amd/src/bootstrap/dropdown.js
            // Dropdown#_addEventListeners, called from its constructor), which
            // permanently attaches its own click handler directly on this same
            // trigger — bubble phase, calling stopPropagation() and toggling
            // the dropdown itself. Since the trigger sits below this container
            // in the tree, that handler would fire before a bubble-phase
            // listener here ever could, so every click after the trigger's
            // first open would be intercepted there — reopening whatever
            // stale content is already rendered instead of ever reaching this
            // handler's own fetch-then-open flow. Capture runs on the way
            // down, ahead of any of the trigger's own bubble listeners, so
            // stopping it here always wins regardless of how many times this
            // trigger has already been opened before.
            e.stopPropagation();
            openMenuForTrigger(container, trigger, state).catch(Notification.exception);
            return;
        }

        const pickedItem = e.target.closest('[data-source-cmid]');
        if (pickedItem) {
            pickTemplate(pickedItem, markDirty);
            return;
        }

        const removeBtn = e.target.closest('[data-region="instance-remove"]');
        if (removeBtn) {
            removeInstanceRow(removeBtn.closest('[data-for="instancerow"]'));
            markDirty();
            return;
        }

        const promptToggle = e.target.closest('[data-region="instance-prompt-toggle"]');
        if (promptToggle) {
            togglePromptDrawer(container, promptToggle.dataset.id);
        }
    }, true);

    container.addEventListener('input', (e) => {
        if (e.target.matches('[data-region="instance-name"], [data-region="instance-prompt"]')) {
            markDirty();
        }
    });
};
