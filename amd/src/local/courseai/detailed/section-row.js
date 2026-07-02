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
 * Section row factory for the detailed plan UI.
 *
 * Builds a li.section.course-section element (Moodle "Custom sections" markup)
 * appended into the ul.course-content container so Boost styles it natively.
 *
 * @module     local_coursegen/local/courseai/detailed/section-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createAddTriggerBtn} from './icons';
import {openInlineAddPanel} from 'local_coursegen/local/courseai/ui/panel';
import {wireDragAndDrop, sendReorderActivities} from './dnd';
import {buildSectionRowSkeleton, buildSectionActionControls} from './section-dom';
import {removeTransientSectionPlaceholders, markProposalTargetPending} from './pending';
import {getSectionList} from './container';

/**
 * Toggle the collapse panel of a section (Boost .collapse / .collapsed classes).
 *
 * @param {HTMLElement}     bodyEl   - The .content.collapse panel.
 * @param {HTMLAnchorElement} chevron - The icons-collapse-expand toggle anchor.
 */
const toggleSectionCollapse = (bodyEl, chevron) => {
    const isOpen = bodyEl.classList.contains('show');
    bodyEl.classList.toggle('show', !isOpen);
    chevron.classList.toggle('collapsed', isOpen);
    chevron.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
};

/**
 * Create and append a section row to the course-content list.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {string} options.sectionId
 * @param {number} options.renderIndex
 * @param {string} options.sectionName
 * @param {number} options.totalActivities
 * @returns {{bodyEl: HTMLElement, activityDnd: Object}|null}
 */
export const createDetailedSectionRow = (ctx, {sectionId, renderIndex, sectionName, totalActivities}) => {
    const {state, texts, runPlanAction, createTextPanel, log} = ctx;
    const sectionList = getSectionList(ctx);

    if (!sectionList) {
        return null;
    }

    const {
        metaEl, imagesBadgeEl, bodyEl, cmlistEl, chevronEl, titleEl, actionsEl, rowRef,
    } = buildSectionRowSkeleton(ctx, sectionId, renderIndex, sectionName, totalActivities);

    const {iaControl, deleteControl, sectionPanelApi} = buildSectionActionControls(
        ctx, sectionId, sectionName, rowRef
    );

    actionsEl.appendChild(metaEl);
    actionsEl.appendChild(imagesBadgeEl);
    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    // Section header — Moodle: div.d-flex.align-items-center wrapping toggle + title.
    const headerEl = document.createElement('div');
    headerEl.className = 'course-section-header d-flex align-items-center position-relative';
    headerEl.setAttribute('data-for', 'section_title');
    headerEl.setAttribute('data-id', sectionId);
    headerEl.appendChild(chevronEl);
    headerEl.appendChild(titleEl);
    headerEl.appendChild(actionsEl);

    // The collapse toggle expands/collapses the section content panel. We own this
    // explicitly (see section-dom.js): stopPropagation prevents any delegated
    // document-level handler (e.g. Bootstrap's collapse data-api) from also acting
    // on the click and cancelling our toggle.
    chevronEl.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleSectionCollapse(bodyEl, chevronEl);
    });

    // Insertion position for the NEXT add-activity submit. null = append at the end
    // (the bottom "+ Add activity" button); a number = insert at that slot (set by an
    // on-hover divider "+" between two activities, so the user can add BETWEEN rows,
    // exactly like Moodle's edit view). Reset to null after every submit.
    let pendingPosition = null;

    // "+ Add activity" control at the bottom of this section's content panel.
    const addActivityPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            addActivityBtn.classList.add('dp-add-control--disabled');
            const position = pendingPosition;
            pendingPosition = null;
            const intent = {
                action: 'add_activity',
                parent_section_id: sectionId,
                instruction: value,
            };
            // Only send an explicit slot when inserting between rows; omit it for
            // the bottom button so the service appends (unchanged behaviour).
            if (typeof position === 'number' && position >= 0) {
                intent.position = position;
            }
            // Same as the inline "+" divider and the chat proposal flow: show the
            // user's request as a left turn AND a placement skeleton in the centre.
            if (typeof log === 'function') {
                log({actor: 'user', kind: 'user', message: value});
            }
            markProposalTargetPending(ctx, intent);
            try {
                await runPlanAction(intent);
            } catch (e) {
                addActivityBtn.classList.remove('dp-add-control--disabled');
            }
        },
        placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
    });

    // Open the add-activity input at a slot. With an anchor (the "+" divider between
    // rows), the input appears INLINE right there — where the user clicked — instead of
    // the section's bottom panel. Without an anchor (the bottom button) it uses the
    // shared bottom panel and appends.
    const openAddActivityAt = (position, anchorEl) => {
        if (anchorEl) {
            openInlineAddPanel({
                anchor: anchorEl,
                texts,
                placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
                onSubmit: async(value) => {
                    const intent = {action: 'add_activity', parent_section_id: sectionId, instruction: value};
                    if (typeof position === 'number' && position >= 0) {
                        intent.position = position;
                    }
                    // Show the user's request verbatim as a left-panel turn (their own
                    // message, like a chat bubble) — not the AI's understanding.
                    if (typeof log === 'function') {
                        log({actor: 'user', kind: 'user', message: value});
                    }
                    // Show a skeleton placeholder at the target slot so the CENTER shows
                    // WHERE the new activity will land while it streams in.
                    markProposalTargetPending(ctx, intent);
                    try {
                        await runPlanAction(intent);
                    } catch (e) {
                        // Non-fatal: the next action re-streams and corrects state.
                    }
                },
            });
            return;
        }
        pendingPosition = typeof position === 'number' ? position : null;
        addActivityPanelApi.open();
    };

    const addActivityBtn = createAddTriggerBtn(texts.courseai_btn_add_activity || 'Add activity');
    addActivityBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        addActivityPanelApi.open();
    });

    const addActivityWrap = document.createElement('li');
    addActivityWrap.className = 'dp-add-activity-wrap';
    addActivityWrap.appendChild(addActivityBtn);
    addActivityWrap.appendChild(addActivityPanelApi.panel);

    // The cmlist holds activity rows AND the trailing add-activity control, so
    // the reconciler reorders activity nodes against the add-activity sentinel.
    cmlistEl.appendChild(addActivityWrap);

    // Section item card (Moodle: div.section-item with border + radius from Boost).
    const sectionItem = document.createElement('div');
    sectionItem.className = 'section-item';
    sectionItem.appendChild(headerEl);
    sectionItem.appendChild(sectionPanelApi.panel);
    sectionItem.appendChild(bodyEl);

    const row = document.createElement('li');
    row.id = `section-${renderIndex + 1}`;
    row.className = 'section course-section main clearfix';
    row.setAttribute('data-for', 'section');
    row.setAttribute('data-id', sectionId);
    row.setAttribute('data-number', String(renderIndex + 1));
    row.setAttribute('data-sectionname', sectionName || '');
    // Kept for the existing DnD wirer (idDataset 'sectionId') and ui-proposals.
    row.dataset.sectionId = sectionId;

    // On-hover "+" divider ABOVE this section (add a section at THIS slot), mirroring
    // the between-activities divider and Moodle's between-sections add affordance. The
    // zone is a child of the section <li> so it rides along on reorder (reconciler-safe).
    // CSS hides it on the FIRST section (Moodle shows dividers only BETWEEN sections).
    const sectionInsertZone = document.createElement('div');
    sectionInsertZone.className = 'cg-insert-zone cg-insert-zone--section';
    sectionInsertZone.setAttribute('contenteditable', 'false');
    sectionInsertZone.setAttribute('draggable', 'false');
    const sectionInsertBtn = document.createElement('button');
    sectionInsertBtn.type = 'button';
    sectionInsertBtn.className = 'cg-insert-btn';
    const secInsertLabel = (texts && texts.courseai_btn_add_section) || 'Add section';
    sectionInsertBtn.setAttribute('aria-label', secInsertLabel);
    sectionInsertBtn.setAttribute('title', secInsertLabel);
    sectionInsertBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
        + 'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">'
        + '<path d="M12 5v14M5 12h14"/></svg>';
    sectionInsertBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (typeof state.openAddSectionAt !== 'function') {
            return;
        }
        // Insert BEFORE this section: its CURRENT index among the rendered sections
        // (computed at click time, so reorders never leave a stale slot).
        const secs = Array.prototype.slice.call(sectionList.querySelectorAll('.course-section'));
        const index = secs.indexOf(row);
        // Pass this section row as the anchor so the input opens INLINE right here.
        state.openAddSectionAt(index >= 0 ? index : null, row);
    });
    sectionInsertZone.addEventListener('dragstart', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });
    sectionInsertZone.appendChild(sectionInsertBtn);
    row.appendChild(sectionInsertZone);

    row.appendChild(sectionItem);
    // Place the new section where a transient placeholder marks its slot (an add at a
    // specific position): insert IN ITS PLACE so it appears at the right slot
    // immediately — no append-at-the-end-then-reorder jump. Otherwise insert before the
    // "+ Add section" control (append, kept pinned to the bottom). The transient is
    // removed AFTER, so it is replaced in place, never duplicated.
    const transientSection = sectionList.querySelector('[data-cg-transient="section"]');
    const addSectionWrap = sectionList.querySelector('.dp-add-section-wrap');
    if (transientSection) {
        sectionList.insertBefore(row, transientSection);
    } else if (addSectionWrap) {
        sectionList.insertBefore(row, addSectionWrap);
    } else {
        sectionList.appendChild(row);
    }
    removeTransientSectionPlaceholders(ctx);

    // Set row reference for panel/action callbacks.
    rowRef.current = row;

    // Wire activity drag-and-drop within this section's cmlist.
    // The add-activity wrap is not draggable — only .activity children are.
    const activityDnd = wireDragAndDrop(
        cmlistEl,
        '.activity',
        'activityId',
        (ids, movedId) => sendReorderActivities(ctx, sectionId, ids, movedId),
        sectionId,
        () => !ctx.state.isStreaming
    );

    state.detailedSectionMeta[sectionId] = {
        done: 0,
        total: totalActivities,
        imagesCount: 0,
        metaEl,
        imagesBadgeEl,
        bodyEl: cmlistEl,
        contentEl: bodyEl,
        chevronEl,
        row,
        addActivityBtn,
        activityDnd,
        // Called by an on-hover "+" divider to open the add panel at a given slot.
        openAddActivityAt,
    };

    return {bodyEl: cmlistEl, activityDnd};
};

/**
 * Build and append the global "+ Add section" control into the section list.
 * Called once per initDetailedPlanView render (after sections are created).
 *
 * @param {Object} ctx
 */
export const appendAddSectionControl = (ctx) => {
    const {state, texts, runPlanAction, createTextPanel, log} = ctx;
    const sectionList = getSectionList(ctx);

    if (!sectionList) {
        return;
    }

    // Remove any previous instance before re-creating.
    const existing = sectionList.querySelector('.dp-add-section-wrap');
    if (existing) {
        existing.remove();
    }

    // Insertion slot for the NEXT add-section submit. null = append at the end (the
    // bottom "+ Add section" button); a number = insert at that slot (set by an
    // on-hover "+" divider between two sections). Reset after every submit.
    let pendingSectionPosition = null;

    const addSectionPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            addSectionBtn.classList.add('dp-add-control--disabled');
            const position = pendingSectionPosition;
            pendingSectionPosition = null;
            const intent = {action: 'add_section', instruction: value};
            if (typeof position === 'number' && position >= 0) {
                intent.position = position;
            }
            // Same as the inline "+" divider and the chat proposal flow: show the
            // user's request as a left turn AND a placement skeleton in the centre, so
            // clicking the bottom "+ Add section" button looks identical to the others.
            if (typeof log === 'function') {
                log({actor: 'user', kind: 'user', message: value});
            }
            markProposalTargetPending(ctx, intent);
            try {
                await runPlanAction(intent);
            } catch (e) {
                addSectionBtn.classList.remove('dp-add-control--disabled');
            }
        },
        placeholder: texts.courseai_add_section_placeholder || 'Describe the section to add…',
    });

    // Open the add-section input at a slot. With an anchor (the "+" divider between
    // sections) the input opens INLINE right there — where the user clicked — instead
    // of the bottom panel. Without an anchor (the bottom button) it appends.
    state.openAddSectionAt = (position, anchorEl) => {
        if (anchorEl) {
            openInlineAddPanel({
                anchor: anchorEl,
                texts,
                placeholder: texts.courseai_add_section_placeholder || 'Describe the section to add…',
                onSubmit: async(value) => {
                    const intent = {action: 'add_section', instruction: value};
                    if (typeof position === 'number' && position >= 0) {
                        intent.position = position;
                    }
                    // Show the user's request verbatim as a left-panel turn (their own
                    // message, like a chat bubble) — not the AI's understanding.
                    if (typeof log === 'function') {
                        log({actor: 'user', kind: 'user', message: value});
                    }
                    // Skeleton placeholder at the target slot: shows WHERE the new section
                    // will land while it streams in.
                    markProposalTargetPending(ctx, intent);
                    try {
                        await runPlanAction(intent);
                    } catch (e) {
                        // Non-fatal: the next action re-streams and corrects state.
                    }
                },
            });
            return;
        }
        pendingSectionPosition = typeof position === 'number' ? position : null;
        addSectionPanelApi.open();
    };

    const addSectionBtn = createAddTriggerBtn(texts.courseai_btn_add_section || 'Add section');
    addSectionBtn.classList.add('dp-add-control--disabled');
    addSectionBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        addSectionPanelApi.open();
    });

    const wrap = document.createElement('li');
    wrap.className = 'dp-add-section-wrap';
    wrap.appendChild(addSectionBtn);
    wrap.appendChild(addSectionPanelApi.panel);
    sectionList.appendChild(wrap);

    // Expose so enableAllActionControls can enable/disable it.
    state.addSectionBtn = addSectionBtn;
};
