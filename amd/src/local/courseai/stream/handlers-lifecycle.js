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

import {
    hideFeedbackThinking,
    showWorkingIndicator,
    leftHasRealContent,
} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {
    transcriptHasContent,
    finalizeTranscript,
    rebuildTranscriptFromPlan,
} from 'local_coursegen/local/courseai/ui/plan-transcript';

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

    // Surface transient progress as the SINGLE live "working" indicator in the
    // thread (never one turn per status event) so the panel never looks frozen
    // while the server streams. It is cleared when content/sections land or on a
    // terminal lifecycle event.
    if (statusText) {
        showWorkingIndicator(texts, statusText);
    }

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
    // 'error' is NON-FATAL and can arrive mid-early-phase before any section has
    // landed. Clearing the live working indicator here used to leave the LEFT
    // panel blank for a long stretch until the next status/section. Instead, keep
    // the single live indicator visible and track the error text on it — but only
    // while the LEFT still has no real content (checklist/structure). Once real
    // content exists, the indicator is no longer the left's only signal, so drop
    // it as before (a terminal lifecycle event will manage it from there).
    if (leftHasRealContent()) {
        hideFeedbackThinking();
    } else if (errorText) {
        showWorkingIndicator(ctx.texts, errorText);
    }
    if (ctx.prvHeaderSub && errorText) {
        ctx.prvHeaderSub.textContent = errorText;
    }
    if (ctx.pcSubtitle && errorText) {
        ctx.pcSubtitle.textContent = errorText;
    }
    // Nothing the server streams is silent: surface the error as a turn. 'error'
    // is non-fatal and may repeat within a round, so dedup consecutive identical
    // messages to avoid stacking the same turn.
    if (typeof ctx.emitLog === 'function' && errorText && ctx.state.lastErrorLogged !== errorText) {
        ctx.state.lastErrorLogged = errorText;
        ctx.emitLog({actor: 'ai', kind: 'danger', message: errorText});
    }
};

/**
 * Handle 'review_needed' event: reconcile the plan diff, show review actions,
 * and close the stream to prevent EventSource auto-reconnect (which would
 * trigger an endless review_needed storm).
 *
 * Uses the diff-based reconciler so only changed elements animate; unchanged
 * rows are never re-rendered or cleared.
 *
 * @param {Object} data
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const handleReviewNeeded = async(data, ctx) => {
    const {
        state, stepsUi, planningUi, detailedUi, proposalsUi, texts, emitLog,
        ensureStreamContentVisible, hideStreamBar, closeStream,
    } = ctx;

    state.isStreaming = false;
    // The AI responded — drop the live "working" indicator.
    hideFeedbackThinking();

    // The section checklist stays as-is (name + spinner→check). Its per-section
    // Markdown detail (description + activities) sits below each item:
    //  - initial round: the live accumulator already filled each detail in real
    //    time → just settle them (clamp long ones with "Show more"/"Show less").
    //  - keepPlan adjust: the live detail fill is skipped during the re-stream, so
    //    rebuild the checklist + details from the authoritative current_plan.
    if (ctx.keepPlan && Array.isArray(data.current_plan) && data.current_plan.length) {
        rebuildTranscriptFromPlan(data.current_plan);
    } else if (transcriptHasContent()) {
        finalizeTranscript();
    } else if (Array.isArray(data.current_plan) && data.current_plan.length) {
        rebuildTranscriptFromPlan(data.current_plan);
    }

    // The plan has settled: from now on log entries flow BELOW the section checklist.
    // Set this BEFORE emitting the milestone so the AI's "review the plan" message
    // lands AFTER the planned-structure group (chronological: plan first, then the
    // prompt to review), not above it.
    state.planEverReviewed = true;
    // Meaningful milestone: the AI finished this round and is awaiting review. Phrased
    // as the assistant talking to the user (first person, no dashes).
    if (typeof emitLog === 'function') {
        const hasProposals = Array.isArray(data.proposals) && data.proposals.length > 0;
        const message = hasProposals
            ? ((texts && texts.courseai_log_ai_proposals_ready)
                || 'I prepared a few suggestions for you. Review them and choose how you want to continue.')
            : ((texts && texts.courseai_log_ai_review_ready)
                || 'I finished planning your course. Take a look at the plan and tell me if you want any changes.');
        emitLog({actor: 'ai', kind: 'ai', message});
    }
    if (typeof ctx.onStreamEnd === 'function') {
        ctx.onStreamEnd();
    }
    ensureStreamContentVisible();
    if (typeof hideStreamBar === 'function') {
        hideStreamBar();
    }
    stepsUi.setStepState('planning', 'done');
    state.currentStage = 'planning';
    stepsUi.updateFlowNav();

    if (Array.isArray(data.current_plan) && data.current_plan.length > 0) {
        await detailedUi.reconcilePlan(data.current_plan);
    }

    if (typeof detailedUi.finalizePlanView === 'function') {
        detailedUi.finalizePlanView();
    }

    // The plan has settled: mark the whole UI as reviewed so every checklist row reads
    // as done via CSS (see body.cg-plan-reviewed). This is declarative and timing-proof,
    // so a late buffered section/activity event cannot leave a lingering spinner.
    document.body.classList.add('cg-plan-reviewed');
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
    planningUi.showReviewActions(state.planningMode === 'detailed' ? 'detailed' : 'markdown');
    if (proposalsUi && typeof proposalsUi.renderProposals === 'function') {
        proposalsUi.renderProposals(data);
    }
    // Do NOT enable/show the composer here: at review the decision card owns the
    // bottom slot, and showReviewActions hides the composer. Showing it again would
    // stack two input boxes. The composer reappears only when the user clicks
    // "Adjust" (the adjust handler calls setCompactChatState 'enabled').
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
        state, stepsUi, streamMode, markAllDone, texts, emitLog,
        setCompletionStatsFromGeneratedResult, closeStream, createCourseFromSession,
        hideStreamBar,
    } = ctx;
    hideFeedbackThinking();
    if (typeof hideStreamBar === 'function') {
        hideStreamBar();
    }
    // Meaningful milestone: generation finished → one permanent success turn.
    if (typeof emitLog === 'function') {
        emitLog({
            actor: 'ai',
            kind: 'success',
            message: (texts && texts.courseai_log_ai_completed) || 'Course generated',
        });
    }
    if (streamMode === 'generating') {
        markAllDone();
    }
    setCompletionStatsFromGeneratedResult(data.result || []);
    stepsUi.setStepState('planning', 'done');
    stepsUi.setStepState('generating', 'active');
    state.currentStage = 'generating';
    stepsUi.updateFlowNav();
    closeStream();
    if (typeof ctx.onStreamEnd === 'function') {
        ctx.onStreamEnd();
    }
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
        state, stepsUi, detailedUi, texts, streamMode, emitLog,
        markAllDone, closeStream, setCompactChatState, deps,
        localizeMessage, planningSpinner, pcStep, pcSubtitle, hideStreamBar,
    } = ctx;
    state.isStreaming = false;
    hideFeedbackThinking();
    if (typeof ctx.onStreamEnd === 'function') {
        ctx.onStreamEnd();
    }
    // Localize once and reuse for both the chat turn and the subtitle.
    const failedText = data.message
        ? await localizeMessage(data.message)
        : (texts && texts.courseai_error_generic) || 'Generation failed';
    // Meaningful (fatal) milestone: surface failure as a permanent error turn.
    if (typeof emitLog === 'function') {
        emitLog({actor: 'ai', kind: 'danger', message: failedText});
    }
    if (typeof hideStreamBar === 'function') {
        hideStreamBar();
    }
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
        pcSubtitle.textContent = failedText;
    }
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
    setCompactChatState(deps, 'enabled');
};
