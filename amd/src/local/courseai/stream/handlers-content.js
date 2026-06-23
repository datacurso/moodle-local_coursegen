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
 * SSE content event handlers: section, activity, detail, token, course_identity.
 *
 * Each handler signature: (data, ctx) => void
 *
 * @module     local_coursegen/local/courseai/stream/handlers-content
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {hideWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

/**
 * Handle 'activity' event: upsert activity into latestInitialSections and
 * increment the checklist item's remaining counter.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleActivity = (data, ctx) => {
    ctx.flags.contentReceived = true;
    const {state, detailedUi} = ctx;
    const sectionForActivity = (state.latestInitialSections || []).find(
        (s) => s.id === data.section_id
    );
    if (sectionForActivity) {
        const existingActivity = sectionForActivity.activities.find((a) => a.id === data.id);
        if (existingActivity) {
            existingActivity.title = data.title || existingActivity.title;
            existingActivity.deleted = data.deleted;
        } else {
            sectionForActivity.activities.push({
                id: data.id,
                position: data.position,
                deleted: data.deleted,
                activity_type: data.activity_type,
                title: data.title,
                description: data.description || '',
            });
        }
        const round = state.generationRound || 0;
        const checklistItem = document.querySelector(
            `.courseai-checklist-list [data-section-id="${data.section_id}"][data-round="${round}"]`
        );
        if (checklistItem && !data.deleted) {
            const remaining = parseInt(checklistItem.getAttribute('data-remaining') || '0', 10);
            checklistItem.setAttribute('data-remaining', remaining + 1);
        }
    }
    // On a keepPlan re-stream (apply selection / adjust) the server re-streams the
    // existing plan structure without real UUIDs, so the structural sync would render
    // positional-placeholder skeleton rows (s{i}-a{j}) duplicating the already-rendered
    // real-UUID rows. Skip it: the genuinely-new activity still skeletons and fills via
    // its real-UUID detailed_plan_activity path (ensureDetailedEntry creates the row).
    if (!ctx.keepPlan && typeof detailedUi.syncDetailedStructureFromSections === 'function') {
        detailedUi.syncDetailedStructureFromSections(state.latestInitialSections || []);
    }
};

/**
 * Handle 'section' event: upsert section, hide loading overlay, append checklist item.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleSection = (data, ctx) => {
    ctx.flags.contentReceived = true;
    const {state, elements, detailedUi, texts, getOrCreateRoundChecklist} = ctx;

    // A real milestone (a planned section) has landed — drop the transient live
    // "working" indicator so it does not sit below the new permanent turn.
    hideWorkingIndicator();

    const loadingEl = document.getElementById('planningLoading');
    const streamContentEl = document.getElementById('planningStreamContent');
    const leftSkel = document.getElementById('cgLeftSkeleton');
    const centerSkel = document.getElementById('cgCenterSkeleton');
    if (loadingEl) {
        loadingEl.style.display = 'none';
    }
    if (streamContentEl) {
        streamContentEl.style.display = '';
    }
    if (leftSkel) {
        leftSkel.style.display = 'none';
    }
    if (centerSkel) {
        centerSkel.style.display = 'none';
    }

    const sections = Array.isArray(state.latestInitialSections) ? state.latestInitialSections : [];
    const existingSection = sections.find((s) => s.id === data.id);
    if (existingSection) {
        existingSection.name = data.name || existingSection.name;
        existingSection.description = data.description || existingSection.description;
        existingSection.position = data.position;
    } else {
        sections.push({
            id: data.id,
            position: data.position,
            name: data.name || '',
            description: data.description || '',
            activities: [],
        });
        state.latestInitialSections = sections;
    }

    const round = state.generationRound || 0;
    const targetList = (round <= 1)
        ? elements.checklistList
        : getOrCreateRoundChecklist(elements, round, texts);

    if (targetList && data.name) {
        const item = document.createElement('li');
        item.className = 'courseai-checklist-item is-loading';
        item.setAttribute('data-section-id', data.id);
        item.setAttribute('data-round', state.generationRound || 0);
        item.setAttribute('data-remaining', 0);
        item.innerHTML = '<span class="courseai-checklist-check">'
            + '<svg class="spinner-icon" viewBox="0 0 24 24">'
            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
            + '<svg class="check-icon" viewBox="0 0 24 24">'
            + '<polyline points="20 6 9 17 4 12"/></svg></span>'
            + '<span class="courseai-checklist-name">' + data.name + '</span>';
        targetList.appendChild(item);
        const listParent = targetList.closest('.courseai-checklist');
        if (listParent) {
            listParent.classList.remove('hidden');
        }
        if (elements.checklist) {
            elements.checklist.classList.remove('hidden');
        }
    }

    // See handleActivity: skip the structural placeholder skeleton render during a
    // keepPlan re-stream so it never duplicates the kept real-UUID rows.
    if (!ctx.keepPlan && typeof detailedUi.syncDetailedStructureFromSections === 'function') {
        detailedUi.syncDetailedStructureFromSections(state.latestInitialSections || []);
    }
};

/**
 * Handle 'course_identity' event: update course title in state and header.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleCourseIdentity = (data, ctx) => {
    const fullname = String(data.fullname || '').trim();
    if (!fullname) {
        return;
    }
    const {state, texts, emitLog} = ctx;
    state.courseTitle = fullname;
    if (ctx.prvHeaderTitle) {
        ctx.prvHeaderTitle.textContent = fullname;
    }
    // Course identity is a meaningful milestone → one permanent AI turn. Dedup on
    // the LAST logged title (not on state.courseTitle, which resetPlanningState
    // clears on accept) so the same title re-emitted during the generating stream
    // does not duplicate the turn, while a genuinely new title still logs.
    const alreadyLogged = state.courseTitleLogged === fullname;
    if (!alreadyLogged && typeof emitLog === 'function') {
        state.courseTitleLogged = fullname;
        const message = ((texts && texts.courseai_log_ai_course_identity) || 'Course: {$a}')
            .replace('{$a}', fullname);
        emitLog({actor: 'ai', kind: 'ai', message});
    }
};

/**
 * Handle 'detailed_plan_field' event.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleDetailedPlanField = (data, ctx) => {
    ctx.flags.contentReceived = true;
    ctx.detailedUi.handleDetailedPlanField(data);
};

/**
 * Handle 'detailed_plan_activity' event: delegate to detailedUi,
 * advance the progress bar, and mark the section checklist item done
 * when all its activities are planned.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleDetailedPlanActivity = (data, ctx) => {
    ctx.flags.contentReceived = true;
    const {state, detailedUi, stepsUi} = ctx;
    detailedUi.handleDetailedPlanActivity(data);
    state.activitiesPlannedCount = (state.activitiesPlannedCount || 0) + 1;
    const totalDetailed = state.detailedTotal || 1;
    const pct = Math.min(90, (state.activitiesPlannedCount / totalDetailed) * 90);
    stepsUi.setProgress(Math.round(pct));

    if (!data.section_id) {
        return;
    }
    const round = state.generationRound || 0;
    const items = document.querySelectorAll(
        `.courseai-checklist-list [data-section-id="${data.section_id}"][data-round="${round}"]`
    );
    items.forEach((item) => {
        const remaining = parseInt(item.getAttribute('data-remaining') || '1', 10);
        const newRemaining = Math.max(0, remaining - 1);
        item.setAttribute('data-remaining', newRemaining);
        if (newRemaining === 0) {
            item.classList.remove('is-loading');
            item.classList.add('is-done');
        }
    });
};

/**
 * Handle 'token' event: switch plan mode to markdown and append streamed text.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleToken = (data, ctx) => {
    ctx.flags.contentReceived = true;
    const {state, stepsUi, renderPlanMarkdown} = ctx;
    stepsUi.switchPlanMode('markdown');
    state.planBuffer += data.text || '';
    renderPlanMarkdown();
};
