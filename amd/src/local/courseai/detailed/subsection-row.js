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
 * Subsection row factory for the detailed plan UI.
 *
 * A subsection renders as a nested block INSIDE its parent section's cmlist:
 * a header (name + AI-adjust/delete controls) and a nested ul holding the
 * subsection's activity rows. The row deliberately has no `.activity` class,
 * so the parent section's activity drag-and-drop never captures it, and its
 * nested list is a separate container so parent reorders never mix levels.
 *
 * The action controls reuse the SECTION intents (replan_section /
 * delete_section) with the subsection id as target — the service resolves
 * subsections as sections, so no new action types exist.
 *
 * @module     local_coursegen/local/courseai/detailed/subsection-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildSectionActionControls} from './section-dom';

/**
 * Ensure the nested row for a subsection exists inside its parent section.
 * Idempotent: returns the existing meta when already rendered.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {string} options.subsectionId
 * @param {string} options.sectionId    - Parent (top-level) section UUID.
 * @param {string} options.name
 * @param {HTMLElement} options.parentBodyEl - The parent section's cmlist.
 * @returns {Object|null} The meta stored in state.detailedSubsectionMeta.
 */
export const ensureSubsectionRendered = (ctx, {subsectionId, sectionId, name, parentBodyEl}) => {
    const {state, texts} = ctx;
    if (!state.detailedSubsectionMeta) {
        state.detailedSubsectionMeta = {};
    }
    const existing = state.detailedSubsectionMeta[subsectionId];
    if (existing) {
        return existing;
    }
    if (!parentBodyEl) {
        return null;
    }

    const label = name || (texts && texts.courseai_subsection_label) || 'Subsection';

    const row = document.createElement('li');
    row.className = 'cg-subsection';
    row.setAttribute('data-subsection-id', subsectionId);
    row.dataset.subsectionId = subsectionId;
    row.setAttribute('draggable', 'false');

    const headerEl = document.createElement('div');
    headerEl.className = 'cg-subsection-header d-flex align-items-center';

    const iconEl = document.createElement('span');
    iconEl.className = 'cg-subsection-icon d-inline-flex align-items-center me-2';
    iconEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" '
        + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>'
        + '<line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>'
        + '<line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';

    const titleEl = document.createElement('h4');
    titleEl.className = 'cg-subsection-name h5 mb-0 d-flex align-items-center';
    titleEl.textContent = label;

    const actionsEl = document.createElement('div');
    actionsEl.className = 'cg-item-actions cg-item-actions--section ms-auto d-flex align-items-center';

    // Reuse the section action controls: the service resolves subsection ids
    // for replan_section / delete_section, so the same pipeline applies.
    const rowRef = {current: null};
    const {iaControl, deleteControl, sectionPanelApi} = buildSectionActionControls(
        ctx, subsectionId, label, rowRef
    );
    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    headerEl.appendChild(iconEl);
    headerEl.appendChild(titleEl);
    headerEl.appendChild(actionsEl);

    const listEl = document.createElement('ul');
    listEl.className = 'cg-subsection-list section m-0 p-0 img-text d-block';
    listEl.setAttribute('data-for', 'cmlist');

    row.appendChild(headerEl);
    row.appendChild(sectionPanelApi.panel);
    row.appendChild(listEl);
    rowRef.current = row;

    // Subsections always land after the direct activities, before the parent's
    // "+ Add activity" sentinel (the fixed direct-first order of the plan).
    const addWrap = parentBodyEl.querySelector(':scope > .dp-add-activity-wrap');
    if (addWrap) {
        parentBodyEl.insertBefore(row, addWrap);
    } else {
        parentBodyEl.appendChild(row);
    }

    state.detailedSubsectionMeta[subsectionId] = {
        row,
        listEl,
        sectionId,
        name: label,
    };
    return state.detailedSubsectionMeta[subsectionId];
};
