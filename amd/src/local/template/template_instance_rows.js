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
 * DOM shape of a virtual template-instance row: creating one (mirroring
 * exactly what sections_config.php/template_course_sections.mustache
 * render server-side, so a freshly inserted row is indistinguishable from
 * one loaded from a saved template), removing one, and scraping every
 * instance currently in a section back into the plain objects
 * buildSections() (init.js) folds into the save payload.
 *
 * Every row/gap pair inserted keeps this invariant: exactly one
 * data-region="row-gap" strip between any two adjacent content rows (real
 * or instance) — except right before the section's persistent "Add
 * activity" row, which never gets one: that row already covers the same
 * "insert here" position, so a gap right in front of it would just be the
 * same affordance rendered twice. dropGapBeforeAddRow() enforces this
 * after every insert/remove instead of hand-tracking it at each call site.
 *
 * @module     local_coursegen/local/template/template_instance_rows
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {startNameEdit, currentName} from 'local_coursegen/local/template/template_instance_name_edit';

/** @type {number} Client-only counter for unique data-instance-id values on unsaved rows. */
let nextTempId = 1;

/**
 * Render a template into a detached fragment of real DOM elements, so a
 * caller can insertBefore() it at a specific position — the same markup
 * template_course_sections_row.mustache renders server-side for a saved
 * instance, never a hand-built duplicate of it.
 *
 * @param {string} templatename Full component/name, e.g. "local_coursegen/template_row_gap".
 * @param {Object} context Template context.
 * @returns {Promise<DocumentFragment>}
 */
const renderRowFragment = async(templatename, context) => {
    const rendered = await Templates.render(templatename, context);
    const holder = document.createElement('tbody');
    Templates.replaceNodeContents(holder, rendered, '');
    const fragment = document.createDocumentFragment();
    while (holder.firstChild) {
        fragment.appendChild(holder.firstChild);
    }
    return fragment;
};

/**
 * @returns {Promise<DocumentFragment>} One <tr data-region="row-gap"> element
 *     — the trigger's own tooltip renders straight from a language string,
 *     the same as every other gap row.
 */
const buildGapRow = () => renderRowFragment('local_coursegen/template_row_gap', {});

/**
 * @param {Object} data {sourcecmid, sourcename, typelabel, modname, iconurl}
 * @param {string} instanceid Client-side identifier (unique within the page).
 * @returns {Promise<DocumentFragment>} The instance row plus its own (hidden) prompt row.
 */
const buildInstanceRowFragment = (data, instanceid) => renderRowFragment('local_coursegen/template_instance_row', {
    instanceid,
    name: data.sourcename,
    sourcename: data.sourcename,
    sourcecmid: data.sourcecmid,
    modname: data.modname,
    iconurl: data.iconurl,
    typelabel: data.typelabel,
    prompt: '',
});

/**
 * Remove the gap directly in front of the section's persistent add-row, if
 * one is currently there — see this module's own docblock for why one
 * must never sit there. Call after any insert/remove instead of tracking
 * this at each call site.
 *
 * @param {HTMLElement} tbody The section's table body.
 */
const dropGapBeforeAddRow = (tbody) => {
    const addRow = tbody.querySelector('[data-region="add-instance"]');
    const priorRow = addRow?.previousElementSibling;
    if (priorRow && priorRow.classList.contains('tpl-row-gap')) {
        priorRow.remove();
    }
};

/**
 * Insert a new instance (plus its prompt row and its own trailing gap)
 * immediately before the given element, adding a leading gap first if none
 * already precedes it (only possible when the section had no rows at all
 * yet, since every other position already has a gap on both sides).
 *
 * @param {HTMLElement} tbody The section's table body.
 * @param {HTMLElement} beforeEl The row-gap or add-row the trigger belongs to.
 * @param {Object} picked {sourcecmid, sourcename, typelabel} from the instance menu.
 * @returns {Promise<HTMLElement>} The new instance row, once inserted and focused.
 */
export const insertInstanceRow = async(tbody, beforeEl, picked) => {
    const needsLeadingGap = !(beforeEl.previousElementSibling
        && beforeEl.previousElementSibling.classList.contains('tpl-row-gap'));
    if (needsLeadingGap) {
        tbody.insertBefore(await buildGapRow(), beforeEl);
    }

    const instanceid = 'new-' + (nextTempId++);
    const instanceFragment = await buildInstanceRowFragment(picked, instanceid);
    const instanceRow = instanceFragment.querySelector('[data-for="instancerow"]');
    tbody.insertBefore(instanceFragment, beforeEl);
    tbody.insertBefore(await buildGapRow(), beforeEl);
    dropGapBeforeAddRow(tbody);

    startNameEdit(instanceRow.querySelector('[data-region="instance-name-editable"]'));
    return instanceRow;
};

/**
 * Remove an instance row along with its prompt row and its own trailing
 * gap row.
 *
 * @param {HTMLElement} instanceRow The row (data-for="instancerow").
 */
export const removeInstanceRow = (instanceRow) => {
    const tbody = instanceRow.closest('tbody');
    const promptRow = instanceRow.nextElementSibling;
    const gapRow = promptRow && promptRow.classList.contains('tpl-instance-prompt-row')
        ? promptRow.nextElementSibling
        : instanceRow.nextElementSibling;
    if (promptRow && promptRow.classList.contains('tpl-instance-prompt-row')) {
        promptRow.remove();
    }
    if (gapRow && gapRow.classList.contains('tpl-row-gap')) {
        gapRow.remove();
    }
    instanceRow.remove();
    dropGapBeforeAddRow(tbody);
};

/**
 * Scrape every instance currently rendered in one section, in DOM order,
 * resolving each one's position as "immediately after this real cmid" (0
 * for the section start) plus a monotonically increasing sortorder — the
 * exact shape save_template expects.
 *
 * @param {HTMLElement} sectionEl The section card (data-for="section").
 * @returns {Array} {sourcecmid, sourcename, name, typelabel, prompt, aftercmid, sortorder}
 */
export const collectInstancesForSection = (sectionEl) => {
    const instances = [];
    let aftercmid = 0;
    let sortorder = 0;
    sectionEl.querySelectorAll('[data-for="cmitem"], [data-for="instancerow"]').forEach(row => {
        if (row.dataset.for === 'cmitem') {
            aftercmid = parseInt(row.dataset.id, 10);
            return;
        }
        const instanceid = row.dataset.instanceId;
        const promptEl = sectionEl.querySelector(
            '[data-for="instanceprompt"][data-instance-id="' + instanceid + '"] textarea'
        );
        let promptValue = '';
        if (promptEl) {
            promptValue = promptEl.value;
        }
        instances.push({
            sourcecmid: parseInt(row.dataset.sourceCmid, 10),
            sourcename: row.dataset.sourceName,
            modname: row.dataset.modname || '',
            name: currentName(row.querySelector('[data-region="instance-name-editable"]')),
            typelabel: row.querySelector('td.text-muted').textContent.trim(),
            prompt: promptValue,
            aftercmid,
            sortorder: sortorder++,
        });
    });
    return instances;
};
