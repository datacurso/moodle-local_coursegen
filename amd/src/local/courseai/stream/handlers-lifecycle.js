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
 * SSE lifecycle event handlers: status, error, review_needed, completed, failed.
 *
 * Each handler signature: (data, ctx) => void | Promise<void>
 *
 * @module     local_coursegen/local/courseai/stream/handlers-lifecycle
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Handle 'status' event: localize message, update UI text, advance heuristic progress.
 *
 * @param {Object} data
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleStatus = async(data, ctx) => {
    const {
        state, stepsUi, texts, streamMode, syncTrackerFromStatus,
        ensureStreamContentVisible, localizeMessage,
        prvHeaderSub, pcSubtitle, prvLiveNote,
    } = ctx;

    const statusText = data.message ? await localizeMessage(data.message) : (data.text || '');
    const heuristicText = (data.message && data.message.string) || data.text || '';

    if (streamMode === 'generating') {
        ensureStreamContentVisible();
        if (!state.structuredActivityProgress) {
            syncTrackerFromStatus(heuristicText);
        }
    }

    const loadingTextEl = document.querySelector('.planning-loading-text');
    if (loadingTextEl && statusText) {
        loadingTextEl.textContent = statusText;
    }

    const totalActivities = state.phase4TotalActivities || ctx.preservedPhase4Total();

    if (prvHeaderSub) {
        prvHeaderSub.textContent = statusText;
    }
    if (pcSubtitle) {
        pcSubtitle.textContent = statusText;
    }
    if (state.planningMode === 'detailed' && prvLiveNote) {
        prvLiveNote.style.display = 'block';
        prvLiveNote.textContent = texts.courseai_live_note_detailed;
    }

    if (state.currentStage !== 'generating' || totalActivities <= 0) {
        return;
    }

    const startPattern = /^(Designing|Generating Assignment content)/i;
    const completePattern = /ready|Assembling final|configuration ready|with \d+ discussion/i;

    if (startPattern.test(heuristicText)) {
        state.contentGenerationStarted = (state.contentGenerationStarted || 0) + 1;
        const startProgress = Math.min(30, (state.contentGenerationStarted / totalActivities) * 30);
        stepsUi.setProgress(Math.round(startProgress));
        return;
    }
    if (completePattern.test(heuristicText)) {
        state.contentGenerationCurrent = (state.contentGenerationCurrent || 0) + 1;
        const completeProgress = 30 + Math.min(60, (state.contentGenerationCurrent / totalActivities) * 60);
        stepsUi.setProgress(Math.round(completeProgress));
    }
};

/**
 * Handle 'error' event: non-fatal; show localized message in header and progress card.
 *
 * @param {Object} data
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleError = async(data, ctx) => {
    const errorText = await ctx.localizeMessage(data.message);
    if (ctx.prvHeaderSub && errorText) {
        ctx.prvHeaderSub.textContent = errorText;
    }
    if (ctx.pcSubtitle && errorText) {
        ctx.pcSubtitle.textContent = errorText;
    }
};

/**
 * Handle 'review_needed' event: render the plan, show review actions, and close the
 * stream to prevent EventSource auto-reconnect (which would trigger an endless
 * review_needed storm).
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleReviewNeeded = (data, ctx) => {
    const {
        state, stepsUi, planningUi, detailedUi, proposalsUi,
        ensureStreamContentVisible, setCompactChatState, deps, closeStream,
    } = ctx;

    state.isStreaming = false;
    ensureStreamContentVisible();
    stepsUi.setStepState('planning', 'done');
    state.currentStage = 'planning';
    stepsUi.updateFlowNav();

    if (Array.isArray(data.current_plan) && data.current_plan.length > 0) {
        detailedUi.initDetailedPlanView({sections: data.current_plan});
        data.current_plan.forEach((section) => {
            (section.activities || []).forEach((activity) => {
                if (activity.deleted) {
                    return;
                }
                detailedUi.handleDetailedPlanActivity({
                    section_id: section.id,
                    activity_id: activity.id,
                    activity_type: activity.activity_type,
                    title: activity.title,
                    data: activity.detailed_plan || {},
                });
            });
        });
    }

    if (typeof detailedUi.finalizePlanView === 'function') {
        detailedUi.finalizePlanView();
    }
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
    planningUi.showReviewActions(state.planningMode === 'detailed' ? 'detailed' : 'markdown');
    if (proposalsUi && typeof proposalsUi.renderProposals === 'function') {
        proposalsUi.renderProposals(data);
    }
    setCompactChatState(deps, 'enabled');
    // review_needed is a terminal pause — close so EventSource does NOT auto-reconnect.
    closeStream();
};

/**
 * Handle 'completed' event: finalize tracker, set stage, close stream, create course.
 *
 * @param {Object} data
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleCompleted = async(data, ctx) => {
    const {
        state, stepsUi, streamMode, markAllDone,
        setCompletionStatsFromGeneratedResult, closeStream, createCourseFromSession,
    } = ctx;
    if (streamMode === 'generating') {
        markAllDone();
    }
    setCompletionStatsFromGeneratedResult(data.result || []);
    stepsUi.setStepState('planning', 'done');
    stepsUi.setStepState('generating', 'active');
    state.currentStage = 'generating';
    stepsUi.updateFlowNav();
    closeStream();
    await createCourseFromSession();
};

/**
 * Handle 'failed' event: mark stream done, show error state, re-enable chat.
 *
 * @param {Object} data
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleFailed = async(data, ctx) => {
    const {
        state, stepsUi, detailedUi, texts, streamMode,
        markAllDone, closeStream, setCompactChatState, deps,
        localizeMessage, planningSpinner, pcStep, pcSubtitle,
    } = ctx;
    state.isStreaming = false;
    if (streamMode === 'generating') {
        markAllDone();
    }
    stepsUi.setStepState('planning', 'active');
    closeStream();
    if (planningSpinner) {
        planningSpinner.classList.add('done');
    }
    if (pcStep) {
        pcStep.textContent = texts.courseai_state_error;
    }
    if (pcSubtitle) {
        pcSubtitle.textContent = data.message
            ? await localizeMessage(data.message)
            : texts.courseai_error_generic;
    }
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
    setCompactChatState(deps, 'enabled');
};
