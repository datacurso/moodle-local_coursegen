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
 * Activity row factory and state helpers for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/activity-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {gripSvg} from './icons';
import {buildActivityDetailContent} from './detail-content';
import {recalculateEntryImageCount, setImageBadge, updateDetailedHeaderStats} from './badges';
import {buildActivityItem, buildActivityActionControls, attachSkeletonProgress} from './activity-dom';
import {createDetailedSectionRow} from './section-row';

export {clearSectionEntries} from './activity-state';

/**
 * Create and append an activity row inside the given section body.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {string} options.sectionId
 * @param {string} options.activityId
 * @param {string} options.activityType
 * @param {string} options.activityTitle
 * @param {HTMLElement} options.bodyEl
 * @returns {Object} The entry stored in state.detailedActivityEls.
 */
export const createDetailedActivityRow = (ctx, {sectionId, activityId, activityType, activityTitle, bodyEl}) => {
    const {state, texts} = ctx;

    const {item, imageBadgeEl, actionsEl, chevronEl, textDiv} = buildActivityItem(ctx, activityType, activityTitle);

    const detailEl = document.createElement('div');
    detailEl.className = 'dp-act-detail';
    detailEl.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.className = 'dp-activity-wrap';
    wrap.dataset.activityId = activityId;

    const {iaControl, deleteControl, activityPanelApi} = buildActivityActionControls(
        ctx, activityId, activityTitle, wrap
    );

    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    // Activity drag handle (appears before the item content).
    const activityHandle = document.createElement('span');
    activityHandle.className = 'dp-drag-handle dp-drag-handle--activity';
    activityHandle.innerHTML = gripSvg;
    activityHandle.setAttribute('aria-label', texts.courseai_drag_handle_label || 'Drag to reorder');
    activityHandle.setAttribute('role', 'img');

    wrap.appendChild(activityHandle);
    wrap.appendChild(item);
    wrap.appendChild(activityPanelApi.panel);
    wrap.appendChild(detailEl);

    // Insert before the add-activity wrap (last child of bodyEl when present).
    const addWrap = bodyEl.querySelector('.dp-add-activity-wrap');
    if (addWrap) {
        bodyEl.insertBefore(wrap, addWrap);
    } else {
        bodyEl.appendChild(wrap);
    }

    // Wire this new wrap into the section's existing DnD setup.
    const sectionMeta = state.detailedSectionMeta[sectionId];
    if (sectionMeta && sectionMeta.activityDnd) {
        sectionMeta.activityDnd.attachToRow(wrap);
    }

    const progressEl = attachSkeletonProgress(textDiv);

    state.detailedActivityEls[activityId] = {
        item, wrap, textDiv, progressEl, detailEl, imageBadgeEl, chevronEl,
        sectionId, previewDescription: '', chapterCount: 0, questionCount: 0,
        imageCount: 0, imageSuggestions: [], hasDetail: false, done: false,
    };

    item.addEventListener('click', () => {
        const entry = state.detailedActivityEls[activityId];
        if (!entry || !entry.hasDetail) {
            return;
        }
        const isOpen = entry.detailEl.style.display !== 'none';
        entry.detailEl.style.display = isOpen ? 'none' : 'block';
        entry.chevronEl.classList.toggle('prv-chevron--open', !isOpen);
    });

    return state.detailedActivityEls[activityId];
};

/**
 * Ensure a section row exists for sectionId; create one lazily if needed.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 * @returns {Object|null}
 */
export const ensureDetailedSection = (ctx, sectionId) => {
    const {state, texts, formatTemplate} = ctx;
    let meta = state.detailedSectionMeta[sectionId];
    if (meta) { return meta; }
    let sectionName = '';
    if (Array.isArray(state.latestInitialSections)) {
        const byId = state.latestInitialSections.find((s) => s.id === sectionId);
        if (byId && typeof byId.name === 'string') { sectionName = byId.name; }
    }
    if (!sectionName && Array.isArray(state.planSectionsData)) {
        const plannedSection = state.planSectionsData.find((section) => section.id === sectionId);
        if (plannedSection && typeof plannedSection.name === 'string') { sectionName = plannedSection.name; }
    }
    const renderIndex = Object.keys(state.detailedSectionMeta).length;
    createDetailedSectionRow(ctx, {
        sectionId, sectionIndex: renderIndex, renderIndex,
        sectionName: sectionName || formatTemplate(texts.courseai_section_label, {section: renderIndex + 1, name: ''}),
        totalActivities: 0,
    });
    meta = state.detailedSectionMeta[sectionId];
    if (meta) { meta.bodyEl.style.display = 'flex'; }
    return meta;
};

/**
 * Ensure an activity entry exists for data.activity_id; create lazily if needed.
 *
 * @param {Object} ctx
 * @param {Object} data
 * @returns {Object|null}
 */
export const ensureDetailedEntry = (ctx, data) => {
    const {state, texts, formatTemplate} = ctx;
    const activityId = data.activity_id;
    if (state.detailedActivityEls[activityId]) { return state.detailedActivityEls[activityId]; }
    const sectionId = data.section_id;
    const meta = ensureDetailedSection(ctx, sectionId);
    if (!meta) { return null; }
    meta.total += 1;
    meta.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
        done: meta.done, total: meta.total, description: '',
    });
    return createDetailedActivityRow(ctx, {
        sectionId, activityId,
        sectionIndex: meta.total - 1, activityIndex: meta.total - 1,
        activityType: data.activity_type || 'quiz',
        activityTitle: data.title || texts.courseai_activity_default,
        bodyEl: meta.bodyEl,
    });
};

/**
 * Mark an activity as fully planned: update DOM and state.
 *
 * @param {Object} ctx
 * @param {Object} data
 */
export const markActivityPlanned = (ctx, data) => {
    const {state, texts, formatTemplate, setProgress} = ctx;
    const entry = ensureDetailedEntry(ctx, data);
    if (!entry || entry.done) {
        return;
    }

    state.detailedCurrent += 1;
    entry.done = true;
    entry.item.classList.remove('prv-activity-item--pending');
    entry.item.classList.add('prv-activity-item--done');

    // Update progress (cap at 95% to avoid reaching 100% prematurely).
    if (typeof setProgress === 'function') {
        const progress = Math.min(95, (state.detailedCurrent / Math.max(1, state.detailedTotal)) * 100);
        setProgress(progress);
    }

    if (entry.progressEl) {
        entry.progressEl.remove();
        entry.progressEl = null;
    }

    const parsed = data.data || {};
    const imageSuggestions = Array.isArray(parsed.image_suggestions) ? parsed.image_suggestions : [];

    entry.imageSuggestions = imageSuggestions.map((suggestion) => {
        const suggestionId = suggestion.id;
        if (suggestion.deleted) {
            delete state.selectedDetailedImages[suggestionId];
        } else {
            state.selectedDetailedImages[suggestionId] = true;
        }
        return {
            id: suggestionId,
            placement: suggestion.placement || '',
            description: suggestion.description || '',
            deleted: suggestion.deleted || false,
        };
    });

    parsed.image_suggestions = entry.imageSuggestions;
    recalculateEntryImageCount(ctx, entry, data.section_id);

    const descriptionText = parsed.activity_description || entry.previewDescription || '';
    if (descriptionText) {
        const desc = document.createElement('p');
        desc.className = 'prv-activity-desc';
        desc.textContent = descriptionText;
        entry.textDiv.appendChild(desc);
    }

    const detailContent = buildActivityDetailContent(ctx, {parsed});
    if (detailContent.childNodes.length > 0) {
        entry.detailEl.innerHTML = '';
        entry.detailEl.appendChild(detailContent);
        entry.hasDetail = true;
        entry.item.classList.add('prv-activity-item--has-detail');
        entry.chevronEl.style.visibility = 'visible';
    }

    const sectionId = data.section_id;
    const meta = state.detailedSectionMeta[sectionId];
    if (meta) {
        meta.done += 1;
        meta.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
            done: meta.done, total: meta.total, description: '',
        });
        setImageBadge(ctx, meta.imagesBadgeEl, meta.imagesCount || 0);
    }

    updateDetailedHeaderStats(ctx);
};
