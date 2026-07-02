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
 * Low-level DOM builders for section rows in the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/section-dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createActionControl} from './controls';
import {iaSparklesSvg, getCoreIconUrl, gripSvg} from './icons';

/**
 * Build the skeleton elements for a section row (meta, badge, body, chevron, btn).
 *
 * @param {Object} ctx
 * @param {string} sectionId
 * @param {number} renderIndex
 * @param {string} sectionName
 * @param {number} totalActivities
 * @returns {{metaEl, imagesBadgeEl, bodyEl, chevronEl, btn, infoDiv, actionsEl, sectionHandle, rowRef}}
 */
export const buildSectionRowSkeleton = (ctx, sectionId, renderIndex, sectionName, totalActivities) => {
    const {texts, formatTemplate} = ctx;

    const metaEl = document.createElement('p');
    metaEl.className = 'prv-section-meta';
    metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
        done: 0, total: totalActivities, description: '',
    });

    const imagesBadgeEl = document.createElement('span');
    imagesBadgeEl.className = 'prv-image-pill';
    imagesBadgeEl.style.display = 'none';

    const metaRowEl = document.createElement('div');
    metaRowEl.className = 'prv-section-meta-row';
    metaRowEl.appendChild(metaEl);
    metaRowEl.appendChild(imagesBadgeEl);

    const bodyEl = document.createElement('div');
    bodyEl.className = 'prv-section-body';
    bodyEl.style.display = 'none';

    const chevronEl = document.createElement('span');
    chevronEl.className = 'prv-chevron';
    chevronEl.innerHTML = [
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
        'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
        'stroke-linejoin="round" aria-hidden="true">',
        '<polyline points="9 18 15 12 9 6"/></svg>'
    ].join(' ');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'prv-section-btn';
    btn.innerHTML = `<span class="prv-section-badge">${renderIndex + 1}</span>`;

    const infoDiv = document.createElement('div');
    infoDiv.className = 'prv-section-info';

    const titleEl = document.createElement('p');
    titleEl.className = 'prv-section-title';
    titleEl.textContent = sectionName
        || formatTemplate(texts.courseai_section_label, {section: renderIndex + 1, name: ''});

    infoDiv.appendChild(titleEl);
    infoDiv.appendChild(metaRowEl);

    const actionsEl = document.createElement('div');
    actionsEl.className = 'dp-item-actions dp-item-actions--section';

    // Drag handle for section row (appears to the left; only it initiates drag).
    const sectionHandle = document.createElement('span');
    sectionHandle.className = 'dp-drag-handle dp-drag-handle--section';
    sectionHandle.innerHTML = gripSvg;
    sectionHandle.setAttribute('aria-label', texts.courseai_drag_handle_label || 'Drag to reorder');
    sectionHandle.setAttribute('role', 'img');

    // Mutable row reference for panel callbacks (assigned after DOM assembly).
    const rowRef = {current: null};

    return {metaEl, imagesBadgeEl, bodyEl, chevronEl, btn, infoDiv, actionsEl, sectionHandle, rowRef};
};

/**
 * Build and attach the IA-adjust and delete action controls for a section.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 * @param {string} sectionName
 * @param {Object} rowRef     - Mutable object {current: HTMLElement|null} shared with callbacks.
 * @returns {{iaControl, deleteControl, sectionPanelApi}}
 */
export const buildSectionActionControls = (ctx, sectionId, sectionName, rowRef) => {
    const {texts, runPlanAction, log, createTextPanel, focusChange, markRemoving, confirmDelete} = ctx;

    let iaControl = null;
    let deleteControl = null;

    const sectionPanelApi = createTextPanel({
        texts,
        onSubmit: async(value) => {
            const row = rowRef.current;
            if (!row) {
                return;
            }
            focusChange(row, 'info');
            row.classList.add('dp-item-regenerating');
            iaControl.classList.add('dp-action-btn--disabled');
            log({
                actor: 'user', kind: 'info',
                message: (texts.courseai_log_regenerated_section || 'You regenerated section «{$a}»')
                    .replace('{$a}', sectionName),
            });
            try {
                await runPlanAction({action: 'replan_section', target_ids: [sectionId], instruction: value});
            } catch (e) {
                row.classList.remove('dp-item-regenerating');
                iaControl.classList.remove('dp-action-btn--disabled');
            }
        },
    });

    iaControl = createActionControl({
        variant: 'ia', iconSvg: iaSparklesSvg,
        label: texts.courseai_btn_adjust,
        onActivate: () => sectionPanelApi.open(),
        disabled: true,
    });

    deleteControl = createActionControl({
        variant: 'delete', iconUrl: getCoreIconUrl('t/delete'),
        label: texts.courseai_btn_cancel,
        onActivate: async() => {
            const row = rowRef.current;
            if (!row) {
                return;
            }
            const confirmed = await confirmDelete({
                title: texts.courseai_delete_section_confirm_title,
                body: texts.courseai_delete_section_confirm_body,
            });
            if (!confirmed) {
                return;
            }
            log({
                actor: 'user', kind: 'danger',
                message: (texts.courseai_log_deleted_section || 'You deleted section «{$a}»')
                    .replace('{$a}', sectionName),
            });
            row.classList.add('dp-item-regenerating');
            deleteControl.classList.add('dp-action-btn--disabled');
            await markRemoving(row);
            try {
                await runPlanAction({action: 'delete_section', target_ids: [sectionId]});
            } catch (e) {
                row.classList.remove('dp-item-regenerating');
                row.classList.remove('cg-removing');
                deleteControl.classList.remove('dp-action-btn--disabled');
            }
        },
        disabled: true,
    });

    return {iaControl, deleteControl, sectionPanelApi};
};
