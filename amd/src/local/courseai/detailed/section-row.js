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
 * @module     local_coursegen/local/courseai/detailed/section-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createAddTriggerBtn} from './icons';
import {wireDragAndDrop, sendReorderActivities} from './dnd';
import {buildSectionRowSkeleton, buildSectionActionControls} from './section-dom';

/**
 * Create and append a section row to prvSections.
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
    const prvSections = ctx.elements.prvSections;

    if (!prvSections) {
        return null;
    }

    const {
        metaEl, imagesBadgeEl, bodyEl, chevronEl, btn,
        infoDiv, actionsEl, sectionHandle, rowRef,
    } = buildSectionRowSkeleton(ctx, sectionId, renderIndex, sectionName, totalActivities);

    const {iaControl, deleteControl, sectionPanelApi} = buildSectionActionControls(
        ctx, sectionId, sectionName, rowRef
    );

    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    btn.appendChild(infoDiv);
    btn.appendChild(actionsEl);
    btn.appendChild(chevronEl);

    btn.addEventListener('click', () => {
        const isOpen = bodyEl.style.display !== 'none';
        bodyEl.style.display = isOpen ? 'none' : 'flex';
        chevronEl.classList.toggle('prv-chevron--open', !isOpen);
    });

    // "+ Add activity" control at the bottom of this section's body.
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

    const addActivityWrap = document.createElement('div');
    addActivityWrap.className = 'dp-add-activity-wrap';
    addActivityWrap.appendChild(addActivityBtn);
    addActivityWrap.appendChild(addActivityPanelApi.panel);

    bodyEl.appendChild(addActivityWrap);

    const row = document.createElement('div');
    row.className = 'prv-section-row';
    row.dataset.sectionId = sectionId;
    row.appendChild(sectionHandle);
    row.appendChild(btn);
    row.appendChild(sectionPanelApi.panel);
    row.appendChild(bodyEl);
    prvSections.appendChild(row);

    // Set row reference for panel/action callbacks.
    rowRef.current = row;

    // Wire activity drag-and-drop within this section's body.
    // The add-activity wrap is not draggable — only dp-activity-wrap children are.
    const activityDnd = wireDragAndDrop(
        bodyEl,
        '.dp-activity-wrap',
        'activityId',
        (ids) => sendReorderActivities(ctx, sectionId, ids),
        sectionId
    );

    state.detailedSectionMeta[sectionId] = {
        done: 0,
        total: totalActivities,
        imagesCount: 0,
        metaEl,
        imagesBadgeEl,
        bodyEl,
        row,
        addActivityBtn,
        activityDnd,
    };

    return {bodyEl, activityDnd};
};

/**
 * Build and append the global "+ Add section" control into prvSections.
 * Called once per initDetailedPlanView render (after sections are created).
 *
 * @param {Object} ctx
 */
export const appendAddSectionControl = (ctx) => {
    const {state, texts, runPlanAction, log, createTextPanel} = ctx;
    const prvSections = ctx.elements.prvSections;

    if (!prvSections) {
        return;
    }

    // Remove any previous instance before re-creating.
    const existing = prvSections.querySelector('.dp-add-section-wrap');
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

    const wrap = document.createElement('div');
    wrap.className = 'dp-add-section-wrap';
    wrap.appendChild(addSectionBtn);
    wrap.appendChild(addSectionPanelApi.panel);
    prvSections.appendChild(wrap);

    // Expose so enableAllActionControls can enable/disable it.
    state.addSectionBtn = addSectionBtn;
};
