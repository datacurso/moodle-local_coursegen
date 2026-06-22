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
 * Emits the same markup as core_courseformat (section.mustache + section/header
 * + section/content + cmlist) so the loaded Boost theme styles the preview
 * identically to a real "Custom sections" course view.
 *
 * @module     local_coursegen/local/courseai/detailed/section-dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createActionControl} from './controls';
import {iaSparklesSvg, getCoreIconUrl} from './icons';

/**
 * Build the chevron toggle anchor (Boost icons-collapse-expand) for a section.
 *
 * @param {string} uuid        - Section UUID, used for the collapse target id.
 * @param {string} sectionName - Accessible label for the toggle.
 * @returns {HTMLAnchorElement}
 */
const buildCollapseToggle = (uuid, sectionName) => {
    const toggle = document.createElement('a');
    toggle.setAttribute('role', 'button');
    toggle.setAttribute('data-toggle', 'collapse');
    toggle.setAttribute('data-for', 'sectiontoggler');
    toggle.setAttribute('href', `#coursecontentcollapseid${uuid}`);
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-controls', `coursecontentcollapseid${uuid}`);
    toggle.setAttribute('aria-label', sectionName || '');
    toggle.className = 'btn btn-icon me-3 icons-collapse-expand justify-content-center';
    const expandedUrl = getCoreIconUrl('t/expandedchevron');
    const collapsedUrl = getCoreIconUrl('t/collapsedchevron');
    toggle.innerHTML =
        '<span class="expanded-icon icon-no-margin p-2">' +
        `<img src="${expandedUrl}" alt="" class="icon"></span>` +
        '<span class="collapsed-icon icon-no-margin p-2">' +
        `<img src="${collapsedUrl}" alt="" class="icon"></span>`;
    return toggle;
};

/**
 * Build the skeleton elements for a section row using Moodle markup.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 * @param {number} renderIndex
 * @param {string} sectionName
 * @param {number} totalActivities
 * @returns {Object} Element references used to assemble the row.
 */
export const buildSectionRowSkeleton = (ctx, sectionId, renderIndex, sectionName, totalActivities) => {
    const {texts, formatTemplate, escapeHtml} = ctx;

    // Progress meta line — kept as a sectionbadge-like element inside the header.
    const metaEl = document.createElement('span');
    metaEl.className = 'cg-section-meta badge bg-light text-muted';
    metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
        done: 0, total: totalActivities, description: '',
    });

    const imagesBadgeEl = document.createElement('span');
    imagesBadgeEl.className = 'cg-image-pill badge bg-light text-muted ms-2';
    imagesBadgeEl.style.display = 'none';

    // Collapse toggle (chevron) — Boost swaps the icon via the .collapsed class.
    const chevronEl = buildCollapseToggle(sectionId, sectionName);

    // Section title heading (Moodle uses h3.h4.sectionname).
    const titleEl = document.createElement('h3');
    titleEl.className = 'h4 sectionname course-content-item d-flex align-self-stretch align-items-center mb-0';
    titleEl.setAttribute('data-for', 'section_title');
    titleEl.setAttribute('data-id', sectionId);
    titleEl.setAttribute('data-number', String(renderIndex + 1));
    titleEl.textContent = sectionName
        || formatTemplate(texts.courseai_section_label, {section: renderIndex + 1, name: ''});

    // Collapse panel (the section body / content) holding the cmlist.
    const bodyEl = document.createElement('div');
    bodyEl.id = `coursecontentcollapseid${escapeHtml(sectionId)}`;
    bodyEl.className = 'content course-content-item-content collapse show';

    // Activity list (ul.section.img-text) — activity <li> rows live here.
    const cmlistEl = document.createElement('ul');
    cmlistEl.className = 'section m-0 p-0 img-text d-block';
    cmlistEl.setAttribute('data-for', 'cmlist');
    bodyEl.appendChild(cmlistEl);

    // Action controls cluster (AI adjust + delete) — placed in the header.
    const actionsEl = document.createElement('div');
    actionsEl.className = 'cg-item-actions cg-item-actions--section ms-auto d-flex align-items-center';

    // Mutable row reference for panel callbacks (assigned after DOM assembly).
    const rowRef = {current: null};

    return {metaEl, imagesBadgeEl, bodyEl, cmlistEl, chevronEl, titleEl, actionsEl, rowRef};
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
            // ONE turn that shows what the user asked AND which section it targets.
            const instruction = (value || '').trim();
            const target = (texts.courseai_section_word || 'Section') + ': ' + sectionName;
            const message = instruction
                ? instruction + ' — ' + target
                : (texts.courseai_log_regenerated_section || 'You regenerated section: {$a}')
                    .replace('{$a}', sectionName);
            log({actor: 'user', kind: 'user', message});
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
