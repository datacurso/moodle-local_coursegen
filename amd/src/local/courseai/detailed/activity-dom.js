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
 * @module     local_coursegen/local/courseai/detailed/activity-dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createActionControl} from './controls';
import {iaSparklesSvg, getCoreIconUrl, activityPurpose} from './icons';

/**
 * Build the item button and its inner structure for an activity row.
 *
 * @param {Object} ctx
 * @param {string} activityType
 * @param {string} activityTitle
 * @returns {{item, rightEl, imageBadgeEl, actionsEl, chevronEl, textDiv}}
 */
export const buildActivityItem = (ctx, activityType, activityTitle) => {
    const {escapeHtml, activityLabels, getActivityIconUrl} = ctx;

    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'prv-activity-item prv-activity-item--pending';

    const iconUrl = getActivityIconUrl(activityType);
    const purpose = activityPurpose[activityType] || 'content';

    item.innerHTML =
        `<span class="ps-badge ps-badge--${escapeHtml(activityType)} dp-purpose-${escapeHtml(purpose)}">` +
        `<img src="${iconUrl}" class="ps-badge-icon" alt="" onerror="this.style.display='none'">` +
        `<span class="ps-badge-text">${escapeHtml(activityLabels[activityType] || activityType)}</span>` +
        `</span>` +
        `<div class="prv-activity-text"><p class="prv-activity-name">${escapeHtml(activityTitle)}</p></div>`;

    const rightEl = document.createElement('div');
    rightEl.className = 'dp-activity-right';

    const imageBadgeEl = document.createElement('span');
    imageBadgeEl.className = 'prv-image-pill prv-image-pill--small';
    imageBadgeEl.style.display = 'none';

    const actionsEl = document.createElement('div');
    actionsEl.className = 'dp-item-actions';

    const chevronEl = document.createElement('span');
    chevronEl.className = 'prv-chevron dp-activity-chevron';
    chevronEl.style.visibility = 'hidden';
    chevronEl.innerHTML = [
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
        'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
        'stroke-linejoin="round" aria-hidden="true">',
        '<polyline points="9 18 15 12 9 6"/></svg>'
    ].join(' ');

    rightEl.appendChild(imageBadgeEl);
    rightEl.appendChild(actionsEl);
    rightEl.appendChild(chevronEl);
    item.appendChild(rightEl);

    const textDiv = item.querySelector('.prv-activity-text');

    return {item, rightEl, imageBadgeEl, actionsEl, chevronEl, textDiv};
};

/**
 * Build the action controls (IA-adjust, delete) for an activity row.
 *
 * @param {Object}      ctx
 * @param {string}      activityId
 * @param {string}      activityTitle
 * @param {HTMLElement} wrap
 * @returns {{iaControl, deleteControl, activityPanelApi}}
 */
export const buildActivityActionControls = (ctx, activityId, activityTitle, wrap) => {
    const {state, texts, runPlanAction, log, createTextPanel, focusChange, markRemoving, confirmDelete} = ctx;

    let iaControl = null;
    let deleteControl = null;

    const activityPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            focusChange(wrap, 'info');
            wrap.classList.add('dp-item-regenerating');
            iaControl.classList.add('dp-action-btn--disabled');
            // Surface the user's own instruction in the log, then the generic action line.
            const instruction = (value || '').trim();
            if (instruction) {
                log({actor: 'user', kind: 'user', message: instruction});
            }
            log({
                actor: 'user', kind: 'info',
                message: (texts.courseai_log_regenerated_activity || 'You regenerated activity «{$a}»')
                    .replace('{$a}', activityTitle),
            });
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
 * Attach a skeleton progress placeholder to textDiv.
 *
 * @param {HTMLElement} textDiv
 * @returns {HTMLElement} progressEl
 */
export const attachSkeletonProgress = (textDiv) => {
    const progressEl = document.createElement('div');
    progressEl.className = 'prv-activity-desc cg-skeleton-wrap';
    progressEl.setAttribute('aria-hidden', 'true');

    const skeletonLine1 = document.createElement('span');
    skeletonLine1.className = 'cg-skeleton cg-skeleton-line';
    skeletonLine1.style.width = '80%';

    const skeletonLine2 = document.createElement('span');
    skeletonLine2.className = 'cg-skeleton cg-skeleton-line';
    skeletonLine2.style.width = '55%';

    progressEl.appendChild(skeletonLine1);
    progressEl.appendChild(skeletonLine2);
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

    const oldDesc = entry.textDiv.querySelector('.prv-activity-desc');
    if (oldDesc) {
        oldDesc.remove();
    }
    entry.detailEl.innerHTML = '';
    entry.detailEl.style.display = 'none';
    entry.hasDetail = false;
    entry.item.classList.remove('prv-activity-item--done', 'prv-activity-item--has-detail');
    entry.item.classList.add('prv-activity-item--pending');
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
