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

import {get_string as getString} from 'core/str';

/** @type {number} Client-only counter for unique data-instance-id values on unsaved rows. */
let nextTempId = 1;

/**
 * @param {string} title
 * @returns {HTMLTableRowElement}
 */
const buildGapRow = (title) => {
    const tr = document.createElement('tr');
    tr.className = 'tpl-row-gap';
    tr.setAttribute('data-region', 'row-gap');
    tr.innerHTML = '<td colspan="4"><div class="tpl-row-gap-line">'
        + '<div class="dropdown tpl-instance-dropdown">'
        + '<button type="button" class="tpl-row-gap-plus" data-instance-menu-trigger'
        + ' data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="' + title + '">+</button>'
        + '<div class="dropdown-menu tpl-instance-menu" role="menu"></div>'
        + '</div></div></td>';
    return tr;
};

/**
 * @param {Object} data {sourcecmid, sourcename, typelabel}
 * @param {number} instanceid Client-side identifier (unique within the page).
 * @param {Object} strings Pre-fetched strings (see insertInstanceRow).
 * @returns {HTMLTableRowElement}
 */
const buildInstanceRow = (data, instanceid, strings) => {
    const tr = document.createElement('tr');
    tr.className = 'tpl-row-instance';
    tr.setAttribute('data-for', 'instancerow');
    tr.setAttribute('data-instance-id', instanceid);
    tr.setAttribute('data-source-cmid', data.sourcecmid);
    tr.setAttribute('data-source-name', data.sourcename);
    const badge = strings.badge.replace('{$a}', data.sourcename);
    tr.innerHTML =
        '<td class="align-middle tpl-select-col"></td>'
        + '<td class="align-middle">'
        + '<span class="icon activityicon tpl-instance-icon mr-2"></span>'
        + '<input type="text" class="tpl-instance-name-input" value="' + data.sourcename + '"'
        + ' data-region="instance-name" data-id="' + instanceid + '" aria-label="' + strings.namelabel + '">'
        + '<span class="tpl-badge tpl-badge-instance ml-2">' + badge + '</span>'
        + '</td>'
        + '<td class="align-middle text-muted">' + data.typelabel + '</td>'
        + '<td class="align-middle text-right"><div class="tpl-instance-actions">'
        + '<button type="button" class="tpl-instance-icon-btn" data-region="instance-prompt-toggle"'
        + ' data-id="' + instanceid + '" title="' + strings.prompttitle + '">&#9998;</button>'
        + '<button type="button" class="tpl-instance-icon-btn tpl-instance-icon-danger" data-region="instance-remove"'
        + ' data-id="' + instanceid + '" title="' + strings.removetitle + '">&times;</button>'
        + '</div></td>';
    return tr;
};

/**
 * @param {number} instanceid
 * @param {string} placeholder
 * @returns {HTMLTableRowElement}
 */
const buildPromptRow = (instanceid, placeholder) => {
    const tr = document.createElement('tr');
    tr.className = 'tpl-instance-prompt-row d-none';
    tr.setAttribute('data-for', 'instanceprompt');
    tr.setAttribute('data-instance-id', instanceid);
    tr.innerHTML = '<td colspan="4"><div class="tpl-prompt-field">'
        + '<textarea data-region="instance-prompt" data-id="' + instanceid + '" rows="2"'
        + ' placeholder="' + placeholder + '"></textarea></div></td>';
    return tr;
};

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
    const [addtitle, badge, namelabel, prompttitle, removetitle, placeholder] = await Promise.all([
        getString('template_add_instance', 'local_coursegen'),
        getString('template_instance_badge', 'local_coursegen', '{$a}'),
        getString('template_instance_name', 'local_coursegen'),
        getString('template_instance_prompt_edit', 'local_coursegen'),
        getString('template_instance_remove', 'local_coursegen'),
        getString('template_instance_prompt_placeholder', 'local_coursegen'),
    ]);

    const needsLeadingGap = !(beforeEl.previousElementSibling
        && beforeEl.previousElementSibling.classList.contains('tpl-row-gap'));
    if (needsLeadingGap) {
        tbody.insertBefore(buildGapRow(addtitle), beforeEl);
    }

    const instanceid = 'new-' + (nextTempId++);
    const instanceRow = buildInstanceRow(picked, instanceid, {badge, namelabel, prompttitle, removetitle});
    const promptRow = buildPromptRow(instanceid, placeholder);
    tbody.insertBefore(instanceRow, beforeEl);
    tbody.insertBefore(promptRow, beforeEl);
    tbody.insertBefore(buildGapRow(addtitle), beforeEl);
    dropGapBeforeAddRow(tbody);

    instanceRow.querySelector('.tpl-instance-name-input').focus();
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
 * @returns {Array} {sourcecmid, sourcename, name, typelabel, prompt, anchorcmid, sortorder}
 */
export const collectInstancesForSection = (sectionEl) => {
    const instances = [];
    let anchorcmid = 0;
    let sortorder = 0;
    sectionEl.querySelectorAll('[data-for="cmitem"], [data-for="instancerow"]').forEach(row => {
        if (row.dataset.for === 'cmitem') {
            anchorcmid = parseInt(row.dataset.id, 10);
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
            name: row.querySelector('[data-region="instance-name"]').value,
            typelabel: row.querySelector('td.text-muted').textContent.trim(),
            prompt: promptValue,
            anchorcmid,
            sortorder: sortorder++,
        });
    });
    return instances;
};
