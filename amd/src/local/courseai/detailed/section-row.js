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
import {wireDragAndDrop, sendReorderActivities} from './dnd';
import {buildSectionRowSkeleton, buildSectionActionControls} from './section-dom';
import {removeTransientSectionPlaceholders} from './pending';
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
    const {state, texts, runPlanAction, log, createTextPanel} = ctx;
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

    // "+ Add activity" control at the bottom of this section's content panel.
    const addActivityPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            addActivityBtn.classList.add('dp-add-control--disabled');
            log({
                actor: 'user',
                kind: 'success',
                message: texts.courseai_log_added_activity || 'You added an activity',
            });
            try {
                await runPlanAction({
                    action: 'add_activity',
                    parent_section_id: sectionId,
                    instruction: value,
                });
            } catch (e) {
                addActivityBtn.classList.remove('dp-add-control--disabled');
            }
        },
        placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
    });

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
    row.appendChild(sectionItem);
    // A genuinely-new real-UUID section is being rendered: drop any transient
    // apply placeholder so the shimmer is replaced, never duplicated.
    removeTransientSectionPlaceholders(ctx);
    sectionList.appendChild(row);

    // Set row reference for panel/action callbacks.
    rowRef.current = row;

    // Wire activity drag-and-drop within this section's cmlist.
    // The add-activity wrap is not draggable — only .activity children are.
    const activityDnd = wireDragAndDrop(
        cmlistEl,
        '.activity',
        'activityId',
        (ids) => sendReorderActivities(ctx, sectionId, ids),
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
    const {state, texts, runPlanAction, log, createTextPanel} = ctx;
    const sectionList = getSectionList(ctx);

    if (!sectionList) {
        return;
    }

    // Remove any previous instance before re-creating.
    const existing = sectionList.querySelector('.dp-add-section-wrap');
    if (existing) {
        existing.remove();
    }

    const addSectionPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            addSectionBtn.classList.add('dp-add-control--disabled');
            log({
                actor: 'user',
                kind: 'success',
                message: texts.courseai_log_added_section || 'You added a section',
            });
            try {
                await runPlanAction({action: 'add_section', instruction: value});
            } catch (e) {
                addSectionBtn.classList.remove('dp-add-control--disabled');
            }
        },
        placeholder: texts.courseai_add_section_placeholder || 'Describe the section to add…',
    });

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
