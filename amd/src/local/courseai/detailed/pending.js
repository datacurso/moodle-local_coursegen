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
 * Client-driven pending-skeleton helpers for proposal apply.
 *
 * When the user applies a proposal, the SSE stream reopens in 'planning' mode
 * with keepPlan=true and re-streams the kept plan WITHOUT real UUIDs, so the
 * structural skeleton sync is suppressed (it would duplicate the kept rows).
 * The side effect is that nothing skeletons during apply — the affected element
 * just silently updates or appears.
 *
 * The client already knows EXACTLY what is being applied: the selected
 * proposal's intent carries action + target_ids + parent_section_id + position.
 * These helpers use that intent to put the loading skeleton on the right element
 * the moment apply is dispatched, independent of the chaotic re-stream:
 *   - replan_activity / replan_section: reopen the existing target row(s) so the
 *     incoming detailed_plan fill re-runs (markActivityPlanned bails on done).
 *   - add_activity / add_section: insert a TRANSIENT skeleton placeholder at the
 *     target position. The genuinely-new real-UUID row removes the matching
 *     transient when it is rendered (see removeTransientActivityPlaceholders /
 *     removeTransientSectionPlaceholders, called from the row factories), so a
 *     duplicate never lingers.
 *
 * @module     local_coursegen/local/courseai/detailed/pending
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {reopenActivityEntry, attachSkeletonProgress} from './activity-dom';
import {getSectionList} from './container';

/** Dataset marker key identifying a transient apply placeholder element. */
const TRANSIENT_ATTR = 'data-cg-transient';

/**
 * Reopen every rendered activity belonging to a section so it re-skeletons.
 *
 * @param {Object} ctx
 * @param {string} sectionId
 */
const reopenSectionActivities = (ctx, sectionId) => {
    const {state} = ctx;
    Object.keys(state.detailedActivityEls).forEach((activityId) => {
        const entry = state.detailedActivityEls[activityId];
        // The target container may be a SUBSECTION (the backend addresses
        // sections and subsections through the same id field): activities
        // nested in one carry the parent in sectionId and the subsection in
        // subsectionId, so match either.
        if (entry && (entry.sectionId === sectionId || entry.subsectionId === sectionId)) {
            reopenActivityEntry(ctx, activityId);
        }
    });
};

/**
 * Build a transient skeleton activity row (no state entry, no real UUID).
 *
 * Mirrors enough of the real activity markup (li.activity > .activity-item >
 * .activity-grid > .cg-activity-desc-slot) for the shimmer skeleton to show,
 * then is removed once the real new row is rendered.
 *
 * @returns {HTMLLIElement}
 */
const buildTransientActivityRow = () => {
    const wrap = document.createElement('li');
    wrap.className = 'activity activity-wrapper cg-transient-activity';
    wrap.setAttribute(TRANSIENT_ATTR, 'activity');

    const item = document.createElement('div');
    item.className = 'activity-item focus-control cg-activity--pending';

    const grid = document.createElement('div');
    grid.className = 'activity-grid';

    const textDiv = document.createElement('div');
    textDiv.className = 'activity-altcontent activity-description cg-activity-desc-slot';
    textDiv.style.display = 'none';
    grid.appendChild(textDiv);

    item.appendChild(grid);
    wrap.appendChild(item);
    attachSkeletonProgress(textDiv);
    return wrap;
};

/**
 * Insert a transient skeleton activity row into a section at the given position.
 *
 * @param {Object}      ctx
 * @param {string}      parentSectionId
 * @param {number|null} position          - Zero-based slot, or null/append for last.
 */
const insertTransientActivity = (ctx, parentSectionId, position) => {
    const {state} = ctx;
    // The parent may be a subsection: its meta lives in detailedSubsectionMeta
    // and names its activity list listEl (sections use bodyEl).
    const meta = state.detailedSectionMeta[parentSectionId]
        || (state.detailedSubsectionMeta && state.detailedSubsectionMeta[parentSectionId]);
    const bodyEl = meta && (meta.bodyEl || meta.listEl);
    if (!bodyEl) {
        return;
    }
    const wrap = buildTransientActivityRow();

    // Existing real activity rows in DOM order (exclude the add-activity sentinel).
    const rows = Array.prototype.filter.call(
        bodyEl.children,
        (el) => el.classList.contains('activity') && !el.classList.contains('dp-add-activity-wrap')
    );
    // DIRECT child only: a parent section's list nests each subsection's own
    // add-activity wrap, and inserting before a non-child anchor throws.
    const addWrap = bodyEl.querySelector(':scope > .dp-add-activity-wrap');
    const idx = typeof position === 'number' && position >= 0 ? position : rows.length;
    const ref = idx < rows.length ? rows[idx] : addWrap;
    if (ref) {
        bodyEl.insertBefore(wrap, ref);
    } else {
        bodyEl.appendChild(wrap);
    }
};

/**
 * Insert a transient skeleton SUBSECTION row into a parent section's cmlist.
 * Same skeleton as a transient section (subsection rows reuse the section
 * markup) plus the cg-subsection class so the nesting indent applies.
 *
 * @param {Object}      ctx
 * @param {string}      parentSectionId - Top-level parent section UUID.
 * @param {number|null} position        - Zero-based slot among the parent's
 *                                        subsections, or null/append for last.
 */
const insertTransientSubsection = (ctx, parentSectionId, position) => {
    const {state} = ctx;
    const meta = state.detailedSectionMeta[parentSectionId];
    if (!meta || !meta.bodyEl) {
        return;
    }
    const bodyEl = meta.bodyEl;
    const row = buildTransientSectionRow(ctx);
    row.classList.add('cg-subsection');

    const subsections = Array.prototype.filter.call(
        bodyEl.children,
        (el) => el.classList.contains('cg-subsection') && !el.hasAttribute(TRANSIENT_ATTR)
    );
    const addSubWrap = bodyEl.querySelector(':scope > .dp-add-subsection-wrap');
    const addWrap = bodyEl.querySelector(':scope > .dp-add-activity-wrap');
    const idx = typeof position === 'number' && position >= 0 ? position : subsections.length;
    const ref = idx < subsections.length ? subsections[idx] : (addSubWrap || addWrap);
    if (ref) {
        bodyEl.insertBefore(row, ref);
    } else {
        bodyEl.appendChild(row);
    }
};

/**
 * Build a transient skeleton section row (no state meta, no real UUID).
 *
 * @param {Object} ctx
 * @returns {HTMLLIElement}
 */
const buildTransientSectionRow = (ctx) => {
    const {texts} = ctx;
    const row = document.createElement('li');
    row.className = 'section course-section main clearfix cg-transient-section';
    row.setAttribute(TRANSIENT_ATTR, 'section');

    const sectionItem = document.createElement('div');
    sectionItem.className = 'section-item';

    const headerEl = document.createElement('div');
    headerEl.className = 'course-section-header d-flex align-items-center position-relative';
    const titleEl = document.createElement('h3');
    titleEl.className = 'h4 sectionname course-content-item d-flex align-items-center mb-0';
    titleEl.textContent = (texts && texts.courseai_generating_details) || '';
    headerEl.appendChild(titleEl);

    const bodyEl = document.createElement('div');
    bodyEl.className = 'content course-content-item-content collapse show';
    const cmlistEl = document.createElement('ul');
    cmlistEl.className = 'section m-0 p-0 img-text d-block';
    const li = document.createElement('li');
    li.className = 'activity activity-wrapper';
    const item = document.createElement('div');
    item.className = 'activity-item focus-control cg-activity--pending';
    const grid = document.createElement('div');
    grid.className = 'activity-grid';
    const textDiv = document.createElement('div');
    textDiv.className = 'activity-altcontent activity-description cg-activity-desc-slot';
    textDiv.style.display = 'none';
    grid.appendChild(textDiv);
    item.appendChild(grid);
    li.appendChild(item);
    cmlistEl.appendChild(li);
    bodyEl.appendChild(cmlistEl);
    attachSkeletonProgress(textDiv);

    sectionItem.appendChild(headerEl);
    sectionItem.appendChild(bodyEl);
    row.appendChild(sectionItem);
    return row;
};

/**
 * Insert a transient skeleton section row at the given position.
 *
 * @param {Object}      ctx
 * @param {number|null} position  - Zero-based slot, or null/append for last.
 */
const insertTransientSection = (ctx, position) => {
    const sectionList = getSectionList(ctx);
    if (!sectionList) {
        return;
    }
    const row = buildTransientSectionRow(ctx);
    const sections = Array.prototype.filter.call(
        sectionList.children,
        (el) => el.classList.contains('course-section')
    );
    const addWrap = sectionList.querySelector('.dp-add-section-wrap');
    const idx = typeof position === 'number' && position >= 0 ? position : sections.length;
    const ref = idx < sections.length ? sections[idx] : addWrap;
    if (ref) {
        sectionList.insertBefore(row, ref);
    } else {
        sectionList.appendChild(row);
    }
};

/**
 * Put the loading skeleton on the element(s) a proposal will affect, the moment
 * apply is dispatched. Only the proposal's targets (plus the new transient for
 * add actions) skeleton; unaffected elements are never touched.
 *
 * @param {Object} ctx
 * @param {Object} intent  - Proposal intent: {action, target_ids, parent_section_id, position}.
 */
export const markProposalTargetPending = (ctx, intent) => {
    if (!intent || typeof intent.action !== 'string') {
        return;
    }
    const targetIds = Array.isArray(intent.target_ids) ? intent.target_ids : [];
    const position = typeof intent.position === 'number' ? intent.position : null;

    switch (intent.action) {
        case 'replan_activity':
            targetIds.forEach((id) => reopenActivityEntry(ctx, id));
            break;
        case 'replace_activity':
            // The old activity is deleted and a new one of a different type takes
            // its slot. Skeleton the existing target row so the user sees exactly
            // which element is being replaced while the replacement streams in.
            targetIds.forEach((id) => reopenActivityEntry(ctx, id));
            break;
        case 'replan_section':
            targetIds.forEach((sectionId) => reopenSectionActivities(ctx, sectionId));
            break;
        case 'add_activity':
            if (intent.parent_section_id) {
                insertTransientActivity(ctx, intent.parent_section_id, position);
            }
            break;
        case 'add_section':
            if (intent.parent_section_id) {
                // add_section with a parent creates a SUBSECTION of that
                // section: the skeleton goes into the parent's cmlist, at the
                // subsection slot space, not into the top-level section list.
                insertTransientSubsection(ctx, intent.parent_section_id, position);
            } else {
                insertTransientSection(ctx, position);
            }
            break;
        default:
            break;
    }
};

/**
 * Remove transient activity placeholders from a section's cmlist. Called when a
 * genuinely-new real activity row is rendered so the transient never duplicates.
 *
 * @param {HTMLElement} bodyEl  - The section's ul.section cmlist.
 */
export const removeTransientActivityPlaceholders = (bodyEl) => {
    if (!bodyEl) {
        return;
    }
    // DIRECT children only: a parent section's list nests its subsections'
    // lists, and their pending transients must not be cleared by a sibling
    // activity landing at the parent level (or vice versa).
    bodyEl.querySelectorAll(`:scope > [${TRANSIENT_ATTR}="activity"]`).forEach((el) => el.remove());
};

/**
 * Remove transient section placeholders from the section list. Called when a
 * genuinely-new real section row is rendered so the transient never duplicates.
 *
 * @param {Object} ctx
 */
export const removeTransientSectionPlaceholders = (ctx) => {
    const sectionList = getSectionList(ctx);
    if (!sectionList) {
        return;
    }
    sectionList.querySelectorAll(`[${TRANSIENT_ATTR}="section"]`).forEach((el) => el.remove());
};

/**
 * Remove ALL transient placeholders (activities and sections) everywhere. Called
 * defensively at reconcile start so no stale shimmer survives the settle.
 */
export const removeAllTransientPlaceholders = () => {
    document.querySelectorAll(`[${TRANSIENT_ATTR}]`).forEach((el) => el.remove());
};
