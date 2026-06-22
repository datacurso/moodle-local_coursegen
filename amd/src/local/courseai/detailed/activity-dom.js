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
 * Low-level DOM builders for activity rows in the detailed plan UI.
 *
 * Emits the same markup as core_courseformat (cm.mustache + cm/activity +
 * cm/cmname + cm/cmicon): li.activity.activity-wrapper > div.activity-item >
 * div.activity-grid > .activity-name-area > .activityname (icon + name). The
 * loaded Boost theme provides the grid, separators, hover outline and the
 * purpose-tinted icon — no hand-written approximations.
 *
 * @module     local_coursegen/local/courseai/detailed/activity-dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createActionControl} from './controls';
import {iaSparklesSvg, getCoreIconUrl, activityPurpose} from './icons';

/**
 * Build the activity-item content (icon + name + actions) in Moodle markup.
 *
 * @param {Object} ctx
 * @param {string} activityType
 * @param {string} activityTitle
 * @returns {{item, actionsEl, chevronEl, textDiv, detailEl}}
 */
export const buildActivityItem = (ctx, activityType, activityTitle) => {
    const {escapeHtml, getActivityIconUrl} = ctx;

    const iconUrl = getActivityIconUrl(activityType);
    const purpose = activityPurpose[activityType] || 'content';
    const safeType = escapeHtml(activityType);

    // div.activity-item.focus-control — the Boost card wrapper.
    const item = document.createElement('div');
    item.className = 'activity-item focus-control';
    item.setAttribute('data-region', 'activity-card');
    item.setAttribute('data-activityname', activityTitle);

    // div.activity-grid — Boost grid layout (icon | name | … | actions).
    const grid = document.createElement('div');
    grid.className = 'activity-grid';

    // Icon container — purpose tint comes from Boost (.activityiconcontainer.<purpose>).
    grid.innerHTML =
        `<div class="activity-icon activityiconcontainer smaller ${escapeHtml(purpose)} courseicon ` +
        'align-self-start me-2">' +
        `<img src="${iconUrl}" class="activityicon" alt="" data-region="activity-icon" ` +
        `onerror="this.style.display='none'"></div>` +
        '<div class="activity-name-area activity-instance d-flex flex-column me-2">' +
        `<div class="activitytitle modtype_${safeType} position-relative align-self-start">` +
        `<div class="activityname"><span class="instancename">${escapeHtml(activityTitle)}</span></div>` +
        '</div></div>';

    // Description slot inside the grid (Moodle: .activity-altcontent.activity-description).
    // Holds the streamed activity description / skeleton — always visible once present.
    const textDiv = document.createElement('div');
    textDiv.className = 'activity-altcontent activity-description cg-activity-desc-slot';
    textDiv.style.display = 'none';
    grid.appendChild(textDiv);

    // Collapsible detail slot (chapters / questions / images) — hidden until expanded.
    const detailEl = document.createElement('div');
    detailEl.className = 'activity-altcontent cg-activity-detail';
    detailEl.style.display = 'none';
    grid.appendChild(detailEl);

    // Actions cluster (AI adjust + delete) — Boost grid area "actions".
    const actionsEl = document.createElement('div');
    actionsEl.className = 'activity-actions cg-item-actions align-self-start ms-sm-2';

    // Expand/collapse chevron for the detail slot.
    const chevronEl = document.createElement('span');
    chevronEl.className = 'cg-activity-chevron';
    chevronEl.style.visibility = 'hidden';
    chevronEl.innerHTML = [
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
        'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
        'stroke-linejoin="round" aria-hidden="true">',
        '<polyline points="9 18 15 12 9 6"/></svg>'
    ].join(' ');
    actionsEl.appendChild(chevronEl);
    grid.appendChild(actionsEl);

    item.appendChild(grid);

    return {item, actionsEl, chevronEl, textDiv, detailEl};
};

/**
 * Build the action controls (IA-adjust, delete) for an activity row.
 *
 * @param {Object}      ctx
 * @param {string}      activityId
 * @param {string}      activityTitle
 * @param {HTMLElement} wrap
 * @param {string}      activityType
 * @returns {{iaControl, deleteControl, activityPanelApi}}
 */
export const buildActivityActionControls = (ctx, activityId, activityTitle, wrap, activityType) => {
    const {
        state, texts, runPlanAction, log, createTextPanel, focusChange, markRemoving,
        confirmDelete, activityLabels,
    } = ctx;

    let iaControl = null;
    let deleteControl = null;

    const activityPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            focusChange(wrap, 'info');
            wrap.classList.add('dp-item-regenerating');
            iaControl.classList.add('dp-action-btn--disabled');
            // ONE coherent turn: the user's instruction plus exactly which activity
            // it targets, naming its TYPE (e.g. "Book: Title"). No separate generic
            // line, no guillemets.
            const instruction = (value || '').trim();
            const typeLabel = (activityLabels && activityLabels[activityType]) || activityType || '';
            const target = typeLabel ? typeLabel + ': ' + activityTitle : activityTitle;
            const message = instruction ? instruction + ' — ' + target : target;
            log({actor: 'user', kind: 'user', message});
            // Reopen the entry so the streamed regeneration renders live (progress is
            // visible) and the final reconcile refills it — both paths bail on done.
            reopenActivityEntry(ctx, activityId);
            try {
                await runPlanAction({action: 'replan_activity', target_ids: [activityId], instruction: value});
            } catch (e) {
                wrap.classList.remove('dp-item-regenerating');
                iaControl.classList.remove('dp-action-btn--disabled');
            }
        },
    });

    iaControl = createActionControl({
        variant: 'ia', iconSvg: iaSparklesSvg,
        label: texts.courseai_btn_adjust,
        onActivate: () => activityPanelApi.open(),
        disabled: true,
    });

    deleteControl = createActionControl({
        variant: 'delete', iconUrl: getCoreIconUrl('t/delete'),
        label: texts.courseai_btn_cancel,
        onActivate: async() => {
            const entry = state.detailedActivityEls[activityId];
            if (!entry) {
                return;
            }
            const confirmed = await confirmDelete({
                title: texts.courseai_delete_activity_confirm_title,
                body: texts.courseai_delete_activity_confirm_body,
            });
            if (!confirmed) {
                return;
            }
            log({
                actor: 'user', kind: 'danger',
                message: (texts.courseai_log_deleted_activity || 'You deleted activity «{$a}»')
                    .replace('{$a}', activityTitle),
            });
            wrap.classList.add('dp-item-regenerating');
            deleteControl.classList.add('dp-action-btn--disabled');
            await markRemoving(wrap);
            try {
                await runPlanAction({action: 'delete_activity', target_ids: [activityId]});
            } catch (e) {
                wrap.classList.remove('dp-item-regenerating');
                wrap.classList.remove('cg-removing');
                deleteControl.classList.remove('dp-action-btn--disabled');
            }
        },
        disabled: true,
    });

    return {iaControl, deleteControl, activityPanelApi};
};

/**
 * Attach a skeleton progress placeholder into the detail slot.
 *
 * @param {HTMLElement} textDiv - The in-grid detail slot (.cg-activity-detail).
 * @returns {HTMLElement} progressEl
 */
export const attachSkeletonProgress = (textDiv) => {
    const progressEl = document.createElement('div');
    progressEl.className = 'cg-activity-desc cg-skeleton-wrap';
    progressEl.setAttribute('aria-hidden', 'true');

    const skeletonLine1 = document.createElement('span');
    skeletonLine1.className = 'cg-skeleton cg-skeleton-line';
    skeletonLine1.style.width = '80%';

    const skeletonLine2 = document.createElement('span');
    skeletonLine2.className = 'cg-skeleton cg-skeleton-line';
    skeletonLine2.style.width = '55%';

    progressEl.appendChild(skeletonLine1);
    progressEl.appendChild(skeletonLine2);
    textDiv.style.display = '';
    textDiv.appendChild(progressEl);

    return progressEl;
};

/**
 * Reopen an already-detailed activity entry so its detailed plan can be streamed
 * and rendered anew (used by per-activity regenerate).
 *
 * markActivityPlanned and handleDetailedPlanField both bail on `entry.done`, so a
 * regenerate over a finished activity would otherwise drop every streamed field and
 * never refill the row. This resets the entry to its pre-detail state — done flag,
 * counters, rendered description/detail, and the streaming skeleton — undoing the
 * previous completion bookkeeping so the new pass re-counts cleanly.
 *
 * @param {Object} ctx
 * @param {string} activityId
 */
export const reopenActivityEntry = (ctx, activityId) => {
    const {state} = ctx;
    const entry = state.detailedActivityEls[activityId];
    if (!entry || !entry.done) {
        return;
    }

    entry.done = false;
    state.detailedCurrent = Math.max(0, (state.detailedCurrent || 0) - 1);
    const meta = state.detailedSectionMeta[entry.sectionId];
    if (meta) {
        meta.done = Math.max(0, (meta.done || 0) - 1);
    }

    const oldDesc = entry.textDiv.querySelector('.cg-activity-desc');
    if (oldDesc) {
        oldDesc.remove();
    }
    entry.detailEl.innerHTML = '';
    entry.detailEl.style.display = 'none';
    entry.hasDetail = false;
    entry.item.classList.remove('cg-activity--done', 'cg-activity--has-detail');
    entry.item.classList.add('cg-activity--pending');
    if (entry.chevronEl) {
        entry.chevronEl.style.visibility = 'hidden';
    }
    entry.previewDescription = '';
    entry.chapterCount = 0;
    entry.questionCount = 0;
    entry.imageCount = 0;

    if (!entry.progressEl) {
        entry.progressEl = attachSkeletonProgress(entry.textDiv);
    }
};
