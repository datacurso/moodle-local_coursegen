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
 * resetPlanningState helper — resets all planning/streaming DOM and state
 * back to the initial streaming-start condition.
 *
 * @module     local_coursegen/local/courseai/steps/reset
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Reset all planning-phase DOM nodes and state fields.
 *
 * ctx must contain: state, elements, texts, setProgress
 *
 * @param {Object} options
 * @param {boolean} [options.showLoading=true]
 * @param {Object} ctx
 */
export const resetPlanningState = (options = {}, ctx) => {
    const {state, elements, texts, setProgress} = ctx;
    const showLoading = options.showLoading !== false;

    // Action resume (keepPlan): the user adjusted an existing plan and the stream
    // is re-opening to apply it. Preserve the rendered plan, its DOM and state so
    // the reconciler can diff against it (no teardown → no global flicker). Just
    // keep the streamed content visible instead of the loading overlay.
    if (options.keepPlan === true) {
        const loadingElKeep = document.getElementById('planningLoading');
        const streamContentElKeep = document.getElementById('planningStreamContent');
        const leftSkelKeep = document.getElementById('cgLeftSkeleton');
        const centerSkelKeep = document.getElementById('cgCenterSkeleton');
        if (loadingElKeep) {
            loadingElKeep.style.display = 'none';
        }
        if (streamContentElKeep) {
            streamContentElKeep.style.display = '';
        }
        if (leftSkelKeep) {
            leftSkelKeep.style.display = 'none';
        }
        if (centerSkelKeep) {
            centerSkelKeep.style.display = 'none';
        }
        return;
    }

    state.planBuffer = '';
    state.planningMode = null;
    state.planDetailsOpen = false;
    state.totalSections = 0;
    state.totalActivities = 0;

    // Reset loading/stream content visibility
    const loadingEl = document.getElementById('planningLoading');
    const streamContentEl = document.getElementById('planningStreamContent');
    const leftSkel = document.getElementById('cgLeftSkeleton');
    const centerSkel = document.getElementById('cgCenterSkeleton');
    if (loadingEl) {
        loadingEl.style.display = 'none';
    }
    if (streamContentEl) {
        streamContentEl.style.display = showLoading ? 'none' : '';
    }
    // Both skeletons preview the layout while the first instruction streams; the
    // stream code may flip planningLoading/streamContent on connect, but these
    // dedicated elements are only cleared on real content (handleSection).
    // The LEFT panel always shows a message first (the user prompt turn + the
    // working/status indicator), so a left skeleton is redundant and would
    // coexist with that message — never show it. The CENTER has no message, so
    // its skeleton still previews the course layout while content streams.
    if (leftSkel) {
        leftSkel.style.display = 'none';
    }
    if (centerSkel) {
        centerSkel.style.display = showLoading ? '' : 'none';
    }

    const {
        planMarkdown,
        planSectionsList,
        planDetailedList,
        prvSections,
        planSectionsView,
        planDetailedView,
        planMarkdownView,
        planReviewCard,
        completionView,
        completionSummary,
        planningProgressCard,
        planActions,
        pcDetailsPanel,
        pcToggleRow,
        pcChevron,
        prvLiveNote,
        typingCursor,
        planningSpinner,
        planningCheckIcon,
        pcIconWrap,
        prvHeader,
        prvHeaderTitle,
        prvHeaderSub,
        prvSpinnerIcon,
        prvCheckIcon,
        pcStep,
        pcTitle,
        pcSubtitle,
    } = elements;

    if (planMarkdown) {
        planMarkdown.innerHTML = '';
    }
    if (planSectionsList) {
        planSectionsList.innerHTML = '';
    }
    if (planDetailedList) {
        planDetailedList.innerHTML = '';
    }
    if (prvSections) {
        prvSections.innerHTML = '';
    }
    if (planSectionsView) {
        planSectionsView.style.display = 'none';
    }
    if (planDetailedView) {
        planDetailedView.style.display = 'none';
    }
    if (planMarkdownView) {
        planMarkdownView.style.display = 'none';
    }
    if (planReviewCard) {
        planReviewCard.style.display = 'none';
    }
    if (completionView) {
        completionView.style.display = 'none';
    }
    if (completionSummary) {
        completionSummary.textContent = texts.courseai_completion_summary_default;
    }
    if (planningProgressCard) {
        planningProgressCard.style.display = showLoading ? 'none' : '';
    }
    if (planActions) {
        planActions.style.display = 'none';
    }
    if (pcDetailsPanel) {
        pcDetailsPanel.style.display = 'none';
        pcDetailsPanel.innerHTML = '';
    }
    if (pcToggleRow) {
        pcToggleRow.style.display = 'none';
    }
    if (pcChevron) {
        pcChevron.style.transform = 'rotate(0deg)';
    }
    if (prvLiveNote) {
        prvLiveNote.style.display = 'none';
        prvLiveNote.textContent = '';
    }
    if (typingCursor) {
        typingCursor.classList.remove('hidden');
    }
    if (planningSpinner) {
        planningSpinner.classList.remove('done');
    }
    if (prvHeader) {
        prvHeader.classList.remove('prv-header--done');
        prvHeader.classList.add('prv-header--stream');
    }
    if (prvHeaderTitle) {
        prvHeaderTitle.textContent = texts.courseai_state_structuring;
    }
    if (prvHeaderSub) {
        prvHeaderSub.textContent = texts.courseai_state_starting;
    }
    if (planningSpinner) {
        planningSpinner.style.display = '';
    }
    if (planningCheckIcon) {
        planningCheckIcon.style.display = 'none';
    }
    if (pcIconWrap) {
        pcIconWrap.style.background = '';
        pcIconWrap.style.color = '';
    }
    if (prvSpinnerIcon) {
        prvSpinnerIcon.style.display = '';
    }
    if (prvCheckIcon) {
        prvCheckIcon.style.display = 'none';
    }
    if (pcStep) {
        pcStep.textContent = texts.courseai_state_planning;
    }
    if (pcTitle) {
        pcTitle.textContent = texts.courseai_state_structuring;
    }
    if (pcSubtitle) {
        pcSubtitle.textContent = '';
    }
    setProgress(0);

    state.detailedTotal = 0;
    state.detailedCurrent = 0;
    state.phase4TotalActivities = 0;
    state.contentGenerationStarted = 0;
    state.contentGenerationCurrent = 0;
    state.planSectionsData = [];
    state.latestInitialSections = [];
    state.detailedTotal = 0;
    state.detailedCurrent = 0;
    state.phase4TotalActivities = 0;
    state.contentGenerationStarted = 0;
    state.contentGenerationCurrent = 0;
    state.planSectionsData = [];
    state.latestInitialSections = [];
    state.generationTracker = null;
    state.structuredActivityProgress = false;
    state.activityProgressTotal = 0;
    state.activityProgressStarted = 0;
    state.activityProgressDone = 0;
    state.imageProgressDone = 0;
    state.imageProgressTotal = 0;
    state.detailedActivityEls = {};
    state.detailedSectionMeta = {};
    state.selectedDetailedImages = {};
    state.courseTitle = '';
    state.activitiesPlannedCount = 0;
    state.completionStats = null;
    state.createdCourseUrl = '';
    state.createdCourseResult = null;
    state.generationRound = (state.generationRound || 0) + 1;
    // Fresh planning round: action log entries return above the checklist, and the
    // checklist shows live loading again, until the new plan settles at review_needed.
    state.planEverReviewed = false;
    document.body.classList.remove('cg-plan-reviewed');

    // NOTE: compact chat lifecycle is NOT reset here intentionally.
    // openSSEStream() calls resetPlanningState() at stream start, and the chat
    // must remain visible (disabled) during streaming. Chat reset only happens
    // in backToContext() which is a true full-navigation reset.
};
