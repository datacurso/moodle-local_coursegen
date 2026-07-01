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
 * Builds a li.activity.activity-wrapper element (Moodle cmitem markup) inside
 * the section's ul.section cmlist so the loaded Boost theme styles it natively.
 *
 * @module     local_coursegen/local/courseai/detailed/activity-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildActivityDetailContent} from './detail-content';
import {recalculateEntryImageCount, setImageBadge, updateDetailedHeaderStats} from './badges';
import {buildActivityItem, buildActivityActionControls, attachSkeletonProgress} from './activity-dom';
import {createDetailedSectionRow} from './section-row';
import {removeTransientActivityPlaceholders} from './pending';
import {activityPurpose} from './icons';

export {clearSectionEntries} from './activity-state';

/**
 * Create and append an activity row inside the given section cmlist.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {string} options.sectionId
 * @param {string} options.activityId
 * @param {string} options.activityType
 * @param {string} options.activityTitle
 * @param {HTMLElement} options.bodyEl - The section's ul.section cmlist.
 * @returns {Object} The entry stored in state.detailedActivityEls.
 */
export const createDetailedActivityRow = (ctx, {sectionId, activityId, activityType, activityTitle, bodyEl}) => {
    const {state, escapeHtml} = ctx;

    const {item, actionsEl, chevronEl, textDiv, detailEl} = buildActivityItem(ctx, activityType, activityTitle);

    // li.activity.activity-wrapper — the Moodle cmitem and the DnD unit.
    const safeType = escapeHtml(activityType);
    const wrap = document.createElement('li');
    wrap.className = `activity activity-wrapper ${safeType} modtype_${safeType}`;
    wrap.setAttribute('data-for', 'cmitem');
    wrap.setAttribute('data-id', activityId);
    wrap.setAttribute('data-cmid', activityId);
    // Kept for the existing DnD wirer (idDataset 'activityId') and ui-proposals.
    wrap.dataset.activityId = activityId;
    // Pending until streamed/planned (custom dim state — Boost has no such class).
    item.classList.add('cg-activity--pending');

    const {iaControl, deleteControl, activityPanelApi} = buildActivityActionControls(
        ctx, activityId, activityTitle, wrap, activityType
    );

    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    wrap.appendChild(item);
    wrap.appendChild(activityPanelApi.panel);

    // On-hover insertion divider (Moodle edit-view affordance): a "+" button centered
    // on the dashed line above this activity. Hovering the top strip reveals it; a click
    // opens the section's add-activity panel targeting THIS row's slot, so the new
    // activity lands BETWEEN rows (not only at the end). The button is a child of the
    // .activity wrap, so it rides along on reorder and never confuses the reconciler
    // (which reorders .activity nodes) or the DnD wirer (which keys on .activity).
    const insertZone = document.createElement('div');
    insertZone.className = 'cg-insert-zone';
    insertZone.setAttribute('contenteditable', 'false');
    insertZone.setAttribute('draggable', 'false');
    const insertBtn = document.createElement('button');
    insertBtn.type = 'button';
    insertBtn.className = 'cg-insert-btn';
    const insertLabel = (ctx.texts && ctx.texts.courseai_btn_add_activity) || 'Add activity';
    insertBtn.setAttribute('aria-label', insertLabel);
    insertBtn.setAttribute('title', insertLabel);
    insertBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
        + 'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">'
        + '<path d="M12 5v14M5 12h14"/></svg>';
    insertBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const meta = state.detailedSectionMeta[sectionId];
        if (!meta || typeof meta.openAddActivityAt !== 'function') {
            return;
        }
        // Insert BEFORE this activity: its CURRENT index among the section's rows
        // (computed at click time, so reorders never leave a stale slot).
        const list = wrap.parentElement;
        const rows = list ? Array.prototype.slice.call(list.querySelectorAll('.activity')) : [];
        const index = rows.indexOf(wrap);
        // Pass this activity as the anchor so the input opens INLINE right here.
        meta.openAddActivityAt(index >= 0 ? index : null, wrap);
    });
    // A drag started on the zone must not drag the row.
    insertZone.addEventListener('dragstart', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });
    insertZone.appendChild(insertBtn);
    wrap.insertBefore(insertZone, wrap.firstChild);

    // Place the new row where a transient placeholder marks its slot (an add at a
    // specific position): insert IN ITS PLACE so the row appears at the right slot
    // immediately — no append-at-the-end-then-reorder jump. Falls back to before the
    // add-activity sentinel (append) when there is no placeholder. The transient is
    // removed AFTER, so it is replaced in place, never duplicated.
    const transient = bodyEl.querySelector('[data-cg-transient="activity"]');
    const addWrap = bodyEl.querySelector('.dp-add-activity-wrap');
    if (transient) {
        bodyEl.insertBefore(wrap, transient);
    } else if (addWrap) {
        bodyEl.insertBefore(wrap, addWrap);
    } else {
        bodyEl.appendChild(wrap);
    }
    removeTransientActivityPlaceholders(bodyEl);

    // Wire this new wrap into the section's existing DnD setup.
    const sectionMeta = state.detailedSectionMeta[sectionId];
    if (sectionMeta && sectionMeta.activityDnd) {
        sectionMeta.activityDnd.attachToRow(wrap);
    }

    const progressEl = attachSkeletonProgress(textDiv);

    state.detailedActivityEls[activityId] = {
        item, wrap, textDiv, progressEl, detailEl, imageBadgeEl: null, chevronEl,
        sectionId, previewDescription: '', chapterCount: 0, questionCount: 0,
        imageCount: 0, imageSuggestions: [], hasDetail: false, done: false,
    };

    // Toggle the collapsible detail slot when the activity item is clicked.
    item.addEventListener('click', () => {
        const entry = state.detailedActivityEls[activityId];
        if (!entry || !entry.hasDetail) {
            return;
        }
        const isOpen = entry.detailEl.style.display !== 'none';
        entry.detailEl.style.display = isOpen ? 'none' : 'block';
        entry.chevronEl.classList.toggle('cg-activity-chevron--open', !isOpen);
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
    entry.item.classList.remove('cg-activity--pending');
    entry.item.classList.add('cg-activity--done');

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
        desc.className = 'cg-activity-desc';
        desc.textContent = descriptionText;
        entry.textDiv.style.display = '';
        entry.textDiv.appendChild(desc);
    }

    const detailContent = buildActivityDetailContent(ctx, {parsed});
    if (detailContent.childNodes.length > 0) {
        entry.detailEl.innerHTML = '';
        entry.detailEl.appendChild(detailContent);
        entry.hasDetail = true;
        entry.item.classList.add('cg-activity--has-detail');
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

    // The detail is rendered: clear the "regenerating" dim so the row is live again.
    if (entry.wrap) {
        entry.wrap.classList.remove('dp-item-regenerating');
    }

    updateDetailedHeaderStats(ctx);
};

// activityPurpose re-exported so callers needing the map import from one place.
export {activityPurpose};
