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
 * initDetailedPlanView and flashAddedActivity for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/init-view
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {normalizeInitialSections} from './normalize';
import {createDetailedSectionRow, appendAddSectionControl} from './section-row';
import {createDetailedActivityRow} from './activity-row';
import {wireDragAndDrop, sendReorderSections} from './dnd';
import {getSectionList} from './container';

/**
 * Flash a newly-added activity element with a success highlight.
 *
 * @param {Object}  ctx
 * @param {string}  id      - Activity UUID.
 * @param {boolean} isFirst - Whether this is the first added element (controls scroll).
 */
export const flashAddedActivity = (ctx, id, isFirst) => {
    const {state, focusChange} = ctx;
    const entry = state.detailedActivityEls[id];
    if (!entry) {
        return;
    }
    const el = entry.wrap || entry.item;
    if (!el) {
        return;
    }
    if (isFirst) {
        focusChange(el, 'success');
        return;
    }
    // For subsequent added items: flash without scrolling.
    el.classList.add('cg-mark-success');
    setTimeout(() => el.classList.remove('cg-mark-success'), 1200);
};

/**
 * Initialize (or reset) the detailed plan view.
 *
 * @param {Object} ctx
 * @param {Object} data
 */
export const initDetailedPlanView = (ctx, data) => {
    const {state, texts, switchPlanMode} = ctx;
    const {
        prvSections, planReviewCard, prvLiveNote, prvSpinnerIcon,
        prvCheckIcon, prvHeader, prvHeaderSub, planningSpinner,
    } = ctx.elements;

    const sourceSections = normalizeInitialSections(ctx, data?.sections || []);
    const renderSections = data?.renderSections !== false;

    if (sourceSections.length > 0) {
        state.latestInitialSections = sourceSections;
    }

    const prevActivityIds = state.prevActivityIds;
    const isNewSession = !sourceSections.length || (state.generationRound || 0) <= 1;

    if (prvSections) {
        prvSections.innerHTML = '';
    }
    state.detailedActivityEls = {};
    state.detailedSectionMeta = {};
    state.selectedDetailedImages = {};
    state.sectionDnd = null;
    state.detailedCurrent = 0;
    state.detailedTotal = data?.total_activities ?? sourceSections.reduce(
        (acc, section) => acc + (section.activities || []).length,
        0
    );

    switchPlanMode('detailed');

    if (planReviewCard) {
        planReviewCard.style.display = '';
    }
    if (prvLiveNote) {
        prvLiveNote.style.display = 'block';
        prvLiveNote.textContent = texts.courseai_live_note_detailed;
    }
    if (prvSpinnerIcon) {
        prvSpinnerIcon.style.display = '';
    }
    if (prvCheckIcon) {
        prvCheckIcon.style.display = 'none';
    }
    if (prvHeader) {
        prvHeader.classList.remove('prv-header--done');
        prvHeader.classList.add('prv-header--stream');
    }
    if (prvHeaderSub) {
        prvHeaderSub.textContent = '';
    }
    if (planningSpinner) {
        planningSpinner.classList.remove('done');
    }

    if (!renderSections) {
        // Reset diff baseline: the DOM is cleared, so the next full render
        // should not flash everything as "new".
        state.prevActivityIds = undefined;
        return;
    }

    sourceSections.forEach((section, renderIdx) => {
        const sectionId = section.id || `s${renderIdx}`;
        const sectionRow = createDetailedSectionRow(ctx, {
            sectionId, sectionIndex: renderIdx, renderIndex: renderIdx,
            sectionName: section.name,
            totalActivities: (section.activities || []).length,
        });
        if (!sectionRow) {
            return;
        }
        (section.activities || []).forEach((activity, activityIdx) => {
            const activityId = activity.id || `${sectionId}-a${activityIdx}`;
            createDetailedActivityRow(ctx, {
                sectionId, activityId,
                sectionIndex: renderIdx, activityIndex: activityIdx,
                activityType: activity.activity_type || activity.type || 'quiz',
                activityTitle: activity.title
                    || activity.name
                    || `${texts.courseai_activity_default} ${activityIdx + 1}`,
                bodyEl: sectionRow.bodyEl,
            });
        });
    });

    // "+ Add section" control — appears after all section rows.
    appendAddSectionControl(ctx);

    // Wire section-level drag-and-drop (li.course-section in ul.course-content).
    state.sectionDnd = wireDragAndDrop(
        getSectionList(ctx),
        '.course-section',
        'sectionId',
        (ids, movedId) => sendReorderSections(ctx, ids, movedId),
        null,
        () => !ctx.state.isStreaming
    );

    // Diff-based success marks — collect the current set of rendered activity ids.
    const currentActivityIds = new Set(Object.keys(state.detailedActivityEls));

    if (isNewSession || !prevActivityIds) {
        // First render of a new session: establish baseline without flashing.
        state.prevActivityIds = currentActivityIds;
        return;
    }

    // Determine newly-added ids (present now, absent before).
    const addedIds = [];
    currentActivityIds.forEach((id) => {
        if (!prevActivityIds.has(id)) {
            addedIds.push(id);
        }
    });

    addedIds.forEach((id, idx) => flashAddedActivity(ctx, id, idx === 0));

    // Update baseline for the next render.
    state.prevActivityIds = currentActivityIds;
};
