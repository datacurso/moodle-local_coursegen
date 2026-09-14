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
 * Per-row "Template" visual state and the modal flows that change it. Split
 * out of sections_events.js so that module stays focused on binding the
 * review's controls, not on the template-scope modal's own logic.
 *
 * @module     local_coursegen/local/template/template_row_scope
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openTemplateScopeModal} from './template_scope_modal';
import {removeInstanceRow} from './template_instance_rows';
import Notification from 'core/notification';
import {getStrings} from 'core/str';
import {prefetchStrings} from 'core/prefetch';

/**
 * Reflect a row's template status in the DOM: toggle the row highlight and
 * the "Template" tag's visibility/label. Never opens the modal — this only
 * ever paints state that is already decided (initial render, a resolved
 * modal Save/Cancel, or a bulk apply).
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {boolean} istemplate Whether the row is currently marked "template".
 * @param {number} cmid The row's course module id.
 * @param {Object} state The live wizard state from init.js.
 */
export const applyTemplateVisual = (row, istemplate, cmid, state) => {
    row.classList.toggle('tpl-row-template', istemplate);
    const tag = row.querySelector('[data-region="template-tag"]');
    if (!tag) {
        return;
    }
    tag.classList.toggle('d-none', !istemplate);
    if (istemplate) {
        const scope = state.activityScope[cmid] || 'course';
        tag.textContent = scope === 'section' ? tag.dataset.tagSection : tag.dataset.tagCourse;
    }
};

/**
 * Open the shared scope modal for one row whose action select was just
 * changed TO "template". Saving persists the chosen scope and marks the row;
 * cancelling (in any way — Cancel, Escape, the backdrop) reverts the select
 * back to its prior value.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {HTMLSelectElement} select The row's action select.
 * @param {number} cmid The row's course module id.
 * @param {string} prioraction The action selected immediately before this change.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} onResolved (finalAction) => void, called once the modal closes.
 */
export const openScopeModalForNewSelection = (row, select, cmid, prioraction, state, onResolved) => {
    const tag = row.querySelector('[data-region="template-tag"]');
    openTemplateScopeModal({
        subject: tag ? tag.dataset.name : '',
        scope: state.activityScope[cmid] || 'course',
        onSave: (scope) => {
            state.activityScope[cmid] = scope;
            state.activityAction[cmid] = 'template';
            applyTemplateVisual(row, true, cmid, state);
            onResolved('template');
        },
        onCancel: () => {
            select.value = prioraction;
            state.activityAction[cmid] = prioraction;
            applyTemplateVisual(row, false, cmid, state);
            onResolved(prioraction);
        },
    });
};

/**
 * Handle an action select changing AWAY from "template": if the row has no
 * instances anchored to it, unmark it immediately, same as before. If it
 * does, confirm first — every instance still on the page loses its own
 * source once this row stops being a template mold, so they are removed
 * together with it rather than left pointing at nothing.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {HTMLSelectElement} select The row's action select.
 * @param {string} action The action just selected (not "template").
 * @param {string} prioraction The action selected immediately before this change.
 * @param {number} cmid The row's course module id.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} onResolved (finalAction) => void, called once resolved.
 */
export const confirmUnmarkTemplate = async(container, row, select, action, prioraction, cmid, state, onResolved) => {
    const instanceRows = [...container.querySelectorAll(
        '[data-for="instancerow"][data-source-cmid="' + cmid + '"]'
    )];
    if (!instanceRows.length) {
        applyTemplateVisual(row, false, cmid, state);
        onResolved(action);
        return;
    }

    const [title, body, removelabel] = await getStrings([
        {key: 'template_instance_unmark_confirm_title', component: 'local_coursegen'},
        {key: 'template_instance_unmark_confirm_body', component: 'local_coursegen', param: instanceRows.length},
        {key: 'template_instance_remove', component: 'local_coursegen'},
    ]);
    Notification.confirm(title, body, removelabel, null, () => {
        instanceRows.forEach(removeInstanceRow);
        applyTemplateVisual(row, false, cmid, state);
        onResolved(action);
    }, () => {
        select.value = prioraction;
        onResolved(prioraction);
    });
};

/**
 * Open the shared scope modal to CHANGE an already-template row's scope
 * (triggered by clicking its tag, not by touching the action select).
 * Cancelling leaves the row exactly as it was.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {number} cmid The row's course module id.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
const openScopeModalToEditRow = (row, cmid, state, markDirty) => {
    const tag = row.querySelector('[data-region="template-tag"]');
    openTemplateScopeModal({
        subject: tag ? tag.dataset.name : '',
        scope: state.activityScope[cmid] || 'course',
        onSave: (scope) => {
            state.activityScope[cmid] = scope;
            applyTemplateVisual(row, true, cmid, state);
            markDirty();
        },
    });
};

/**
 * Bind the click handler that reopens the scope modal from a row's tag.
 *
 * Warms the string cache for confirmUnmarkTemplate()'s own getStrings()
 * call, so switching a row away from "template" resolves its confirmation
 * dialog from cache instead of a network round trip.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Object} state The live wizard state from init.js.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindTemplateTagClicks = (container, state, markDirty) => {
    prefetchStrings('local_coursegen', [
        'template_instance_unmark_confirm_title',
        'template_instance_unmark_confirm_body',
        'template_instance_remove',
    ]);
    container.addEventListener('click', (e) => {
        const tag = e.target.closest('[data-region="template-tag"]');
        if (!tag) {
            return;
        }
        const cmid = parseInt(tag.dataset.id);
        const row = tag.closest('[data-for="cmitem"]');
        if (!cmid || !row) {
            return;
        }
        openScopeModalToEditRow(row, cmid, state, markDirty);
    });
};
