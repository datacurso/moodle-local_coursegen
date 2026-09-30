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
 * Event bindings for the add menu ("Add activity from a template" and "Add a
 * space for an activity"): the hover-reveal gap triggers, the section's
 * persistent trigger, and each instance row's prompt-toggle/remove icons. Bound the same way sections_events.js binds
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

import {
    openAddMenu,
    showAddMenu,
    showTemplateList,
    closeInstanceMenu,
    beginMenuOpen,
} from 'local_coursegen/local/template/template_instance_menu';
import {insertInstanceRow, removeInstanceRow} from 'local_coursegen/local/template/template_instance_rows';
import {addSpace, bindSpaceRows} from 'local_coursegen/local/template/template_space_rows';
import {buildAvailableTemplates} from 'local_coursegen/local/template/template_picker_options';
import Notification from 'core/notification';
import {prefetchStrings} from 'core/prefetch';

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
 * Open a trigger's add menu.
 *
 * Claims this open's token synchronously, before any async work starts —
 * see template_instance_menu.js's latestOpenToken doc for why: it is what
 * makes a later click on this same trigger win over an earlier one whose
 * own async work happens to settle later.
 *
 * @param {HTMLElement} trigger The clicked "+" button.
 */
const openMenuForTrigger = async(trigger) => {
    const token = beginMenuOpen(trigger);
    await openAddMenu({triggerEl: trigger, token});
};

/**
 * Switch an open add menu to the list of templates an activity can be
 * created from, for the section the trigger belongs to.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {HTMLElement} trigger The "+" button whose menu is open.
 * @param {Object} state The live wizard state from init.js.
 */
const showTemplatePicker = async(container, trigger, state) => {
    const sectionEl = trigger.closest('[data-for="section"]');
    const sectionid = parseInt(sectionEl.dataset.id, 10);
    const options = await buildAvailableTemplates(container, sectionid, state);
    await showTemplateList({triggerEl: trigger, options});
};

/**
 * Handle a click on one of the add menu's own items.
 *
 * @param {HTMLElement} item The clicked item (data-menu-action).
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
const handleMenuAction = async(item, container, state, markDirty) => {
    const dropdown = item.closest('.dropdown');
    const trigger = dropdown.querySelector('[data-instance-menu-trigger]');
    const action = item.dataset.menuAction;
    if (action === 'from-template') {
        await showTemplatePicker(container, trigger, state);
        return;
    }
    if (action === 'back') {
        await showAddMenu(trigger);
        return;
    }
    if (action === 'add-space') {
        await addSpace(item, state, markDirty);
    }
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
 * Handle a click anywhere in the review that belongs to the add-from-template
 * feature: a "+" trigger, an item of the add menu, a picked template, an
 * instance row's remove icon or its prompt toggle.
 *
 * @param {Object} ctx {container, state, markDirty} of the review.
 * @param {MouseEvent} e The click.
 */
const handleContainerClick = (ctx, e) => {
    const trigger = e.target.closest('[data-instance-menu-trigger]');
    if (trigger) {
        // Bound on the CAPTURE phase (see bindInstanceInserts()), not bubble:
        // openMenuForTrigger()'s own dropdown('toggle')
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
        const opening = openMenuForTrigger(trigger);
        opening.catch(Notification.exception);
        return;
    }

    // The add menu's own items keep the dropdown open (the template
    // picker replaces its content in place), so the click must not reach
    // Bootstrap's document-level handler that closes a dropdown on any
    // click inside it. "add a space" closes it explicitly.
    const menuItem = e.target.closest('[data-menu-action]');
    if (menuItem) {
        e.stopPropagation();
        const handling = handleMenuAction(menuItem, ctx.container, ctx.state, ctx.markDirty);
        handling.catch(Notification.exception);
        return;
    }

    // Scoped to the menu item's own class, not just [data-source-cmid]:
    // every instance row's own <tr> also carries that same attribute
    // (read back by confirmUnmarkTemplate/collectInstancesForSection),
    // so the bare attribute selector matched a click ANYWHERE inside an
    // already-inserted row too, mistaking it for a fresh pick.
    const pickedItem = e.target.closest('.tpl-instance-menu-item[data-source-cmid]');
    if (pickedItem) {
        pickTemplate(pickedItem, ctx.markDirty);
        return;
    }

    const removeBtn = e.target.closest('[data-region="instance-remove"]');
    if (removeBtn) {
        const instanceRow = removeBtn.closest('[data-for="instancerow"]');
        removeInstanceRow(instanceRow);
        ctx.markDirty();
        return;
    }

    const promptToggle = e.target.closest('[data-region="instance-prompt-toggle"]');
    if (promptToggle) {
        togglePromptDrawer(ctx.container, promptToggle.dataset.id);
    }
};

/**
 * Mark the wizard dirty when an instance row's prompt is edited.
 *
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 * @param {InputEvent} e The input event.
 */
const handlePromptInput = (markDirty, e) => {
    if (e.target.matches('[data-region="instance-prompt"]')) {
        markDirty();
    }
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
        'template_add_space',
        'template_instance_badge',
        'template_instance_name',
        'template_instance_prompt_edit',
        'template_instance_remove',
        'template_instance_prompt_placeholder',
    ]);

    // The click listener is added on the CAPTURE phase (3rd argument), see
    // handleContainerClick() for why.
    const ctx = {container, state, markDirty};
    const onClick = handleContainerClick.bind(null, ctx);
    container.addEventListener('click', onClick, true);

    const onInput = handlePromptInput.bind(null, markDirty);
    container.addEventListener('input', onInput);

    bindSpaceRows(container, markDirty);
};
