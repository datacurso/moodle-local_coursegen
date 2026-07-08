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
 * A subsection renders NESTED inside its parent section's cmlist using the
 * SAME Moodle "Custom sections" markup the section rows use (section-item
 * card, Boost collapse chevron, sectionname title, activity-count badge), so
 * the preview mirrors how mod_subsection looks in the real course.
 *
 * The row deliberately has no `.activity` class, so the parent section's
 * activity drag-and-drop never captures it, and its nested cmlist is a
 * separate container so parent reorders never mix levels.
 *
 * The action controls reuse the SECTION intents (replan_section /
 * delete_section) with the subsection id as target — the service resolves
 * subsections as sections, so no new action types exist.
 *
 * @module     local_coursegen/local/courseai/detailed/subsection-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildSectionRowSkeleton, buildSectionActionControls} from './section-dom';
import {wireDragAndDrop, sendReorderSections, sendReorderActivities} from './dnd';

/**
 * Toggle the collapse panel (Boost .collapse / .collapsed classes), same
 * behavior as the section rows.
 *
 * @param {HTMLElement} bodyEl - The .content.collapse panel.
 * @param {HTMLAnchorElement} chevron - The icons-collapse-expand toggle anchor.
 */
const toggleCollapse = (bodyEl, chevron) => {
    const isOpen = bodyEl.classList.contains('show');
    bodyEl.classList.toggle('show', !isOpen);
    chevron.classList.toggle('collapsed', isOpen);
    chevron.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
};

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
        // Re-offered row (reconcile settle pass): make sure it is wired into
        // the parent's subsection DnD — attachToRow is idempotent.
        const parentMeta = state.detailedSectionMeta[existing.sectionId];
        if (parentMeta && parentMeta.subsectionDnd && existing.row) {
            parentMeta.subsectionDnd.attachToRow(existing.row);
        }
        return existing;
    }
    if (!parentBodyEl) {
        return null;
    }

    const label = name || (texts && texts.courseai_subsection_label) || 'Subsection';
    const renderIndex = Object.keys(state.detailedSubsectionMeta).length;

    // Same skeleton the section rows use: meta badge, Boost collapse chevron,
    // sectionname title, .content.collapse body with its cmlist.
    const {
        metaEl, imagesBadgeEl, bodyEl, cmlistEl, chevronEl, titleEl, actionsEl, rowRef,
    } = buildSectionRowSkeleton(ctx, subsectionId, renderIndex, label, 0);

    // Reuse the section action controls: the service resolves subsection ids
    // for replan_section / delete_section, so the same pipeline applies.
    const {iaControl, deleteControl, sectionPanelApi} = buildSectionActionControls(
        ctx, subsectionId, label, rowRef
    );
    actionsEl.appendChild(metaEl);
    actionsEl.appendChild(imagesBadgeEl);
    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    const headerEl = document.createElement('div');
    headerEl.className = 'course-section-header d-flex align-items-center position-relative';
    headerEl.setAttribute('data-for', 'section_title');
    headerEl.setAttribute('data-id', subsectionId);
    headerEl.appendChild(chevronEl);
    headerEl.appendChild(titleEl);
    headerEl.appendChild(actionsEl);

    chevronEl.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleCollapse(bodyEl, chevronEl);
    });

    const sectionItem = document.createElement('div');
    sectionItem.className = 'section-item';
    sectionItem.appendChild(headerEl);
    sectionItem.appendChild(sectionPanelApi.panel);
    sectionItem.appendChild(bodyEl);

    const row = document.createElement('li');
    // cg-subsection marks the nesting level (reconciler / CSS indent); the
    // Moodle classes make Boost style it exactly like a section card.
    row.className = 'cg-subsection section course-section main clearfix';
    row.setAttribute('data-subsection-id', subsectionId);
    row.dataset.subsectionId = subsectionId;
    row.appendChild(sectionItem);
    rowRef.current = row;

    // Subsections always land after the direct activities, before the parent's
    // "+ Add activity" sentinel (the fixed direct-first order of the plan).
    const addWrap = parentBodyEl.querySelector(':scope > .dp-add-activity-wrap');
    if (addWrap) {
        parentBodyEl.insertBefore(row, addWrap);
    } else {
        parentBodyEl.appendChild(row);
    }

    // Activity drag-and-drop WITHIN this subsection: same reorder_activities
    // action, with the subsection as the parent container (the service
    // validates targets against it and cross-container drops are rejected by
    // the origin check in the drop handler).
    const activityDnd = wireDragAndDrop(
        cmlistEl,
        '.activity',
        'activityId',
        (ids, movedId) => sendReorderActivities(ctx, subsectionId, ids, movedId),
        subsectionId,
        () => !ctx.state.isStreaming
    );

    // Subsection drag-and-drop within the PARENT section: reorder_sections
    // with subsection ids (the service reorders them inside their parent).
    // One wirer per parent section, created with the first subsection.
    const parentMeta = state.detailedSectionMeta[sectionId];
    if (parentMeta) {
        if (!parentMeta.subsectionDnd) {
            parentMeta.subsectionDnd = wireDragAndDrop(
                parentBodyEl,
                '.cg-subsection',
                'subsectionId',
                (ids, movedId) => sendReorderSections(ctx, ids, movedId),
                sectionId,
                () => !ctx.state.isStreaming
            );
        } else {
            parentMeta.subsectionDnd.attachToRow(row);
        }
    }

    state.detailedSubsectionMeta[subsectionId] = {
        row,
        listEl: cmlistEl,
        metaEl,
        sectionId,
        name: label,
        activityDnd,
    };
    return state.detailedSubsectionMeta[subsectionId];
};

/**
 * Sync a subsection's activity-count badge to its REAL rendered rows (same
 * approach as refreshSectionMeta for sections).
 *
 * @param {Object} ctx
 * @param {string} subsectionId
 * @returns {void}
 */
export const refreshSubsectionMeta = (ctx, subsectionId) => {
    const {state, texts} = ctx;
    const meta = state.detailedSubsectionMeta && state.detailedSubsectionMeta[subsectionId];
    if (!meta || !meta.metaEl || !meta.listEl) {
        return;
    }
    const count = meta.listEl.querySelectorAll('.activity[data-activity-id]:not([data-cg-transient])').length;
    const label = (texts && texts.courseai_activities_count) || 'activities';
    meta.metaEl.textContent = count + ' ' + label;
};
