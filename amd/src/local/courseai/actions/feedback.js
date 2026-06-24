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
 * sendFeedbackAction helper — sends plan accept/adjust actions via the
 * planning-feedback WS and re-opens the SSE stream.
 *
 * @module     local_coursegen/local/courseai/actions/feedback
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    showFeedbackThinking,
    hideWorkingIndicator,
} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';

/**
 * Emit a log entry if emitLog is wired.
 *
 * @param {Object}   params
 * @param {Function} emitLog
 */
const log = (params, emitLog) => {
    if (typeof emitLog === 'function') {
        emitLog(params);
    }
};

/**
 * Send a plan accept or adjust action, then re-open the appropriate SSE stream.
 *
 * ctx must contain:
 *   state, elements, texts, Notification,
 *   setCompactChatState, deps,
 *   sendPlanningFeedback, streamManager,
 *   stepsUi, planningUi,
 *   emitLog
 *
 * @param {string} action - 'accept' | 'adjust'
 * @param {Object} ctx
 * @returns {Promise<void>}
 */
export const sendFeedbackAction = async(action, ctx) => {
    const {
        state,
        elements,
        texts,
        Notification,
        setCompactChatState,
        deps,
        sendPlanningFeedback,
        streamManager,
        stepsUi,
        planningUi,
        emitLog,
    } = ctx;

    const {
        btnApprove,
        planActions,
        planningSpinner,
        pcSubtitle,
        compactPromptInput,
    } = elements;

    if (!state.sessionid) {
        return;
    }

    // WU4: hide the decision overlay as soon as the user acts (accept or adjust).
    getDecisionOverlay().hide();

    if (btnApprove) {
        btnApprove.disabled = true;
    }
    if (planActions) {
        planActions.style.display = 'none';
    }
    // Disable controls and Regenerar button during stream
    setCompactChatState(deps, 'disabled');
    /* PAUSAR — to be implemented later
    if (action === 'adjust' && btnCompactRegenerate) {
        state.isStreaming = true;
        const pauseIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" ' +
            'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
            'stroke-linejoin="round" aria-hidden="true">' +
            '<rect x="6" y="4" width="4" height="16"/>' +
            '<rect x="14" y="4" width="4" height="16"/></svg>';
        btnCompactRegenerate.innerHTML = `${pauseIcon} ${texts.courseai_btn_pause}`;
        btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_pause);
        btnCompactRegenerate.setAttribute('title', texts.courseai_btn_pause);
        btnCompactRegenerate.disabled = false;
    }
    */
    if (planningSpinner) {
        planningSpinner.classList.remove('done');
    }
    if (pcSubtitle) {
        pcSubtitle.textContent = action === 'accept'
            ? texts.courseai_status_approving
            : texts.courseai_status_adjusting;
    }

    try {
        const instruction = action === 'adjust' && compactPromptInput
            ? compactPromptInput.value.trim()
            : '';

        // Free-text feedback is a post-review action. Mark the plan reviewed so
        // the decision-log feed flows at the END (below the checklist) even after
        // a reload — where the live review_needed handler that normally sets this
        // never ran. Log the user message ONCE (the unified feed; no separate
        // adjustment-history bubble) and show a progress indicator so the feed
        // never looks stuck while the AI interprets the request.
        if (action === 'adjust' && instruction) {
            state.planEverReviewed = true;
            const truncatedInstruction = instruction.length > 80 ? instruction.slice(0, 80) + '…' : instruction;
            // Show the user's OWN words in their turn (e.g. "You: Cambia el primer libro…").
            // The lang string `courseai_log_user_request` is the bare "You" label — it has
            // NO {$a} placeholder, so the old `.replace('{$a}', …)` silently dropped the
            // instruction and the turn rendered just "You". Compose label + instruction
            // explicitly here so it mirrors the thread-replay path (label + ': ' + text).
            const requestLabel = texts.courseai_log_user_request || 'You';
            const adjustMsg = requestLabel + ': ' + truncatedInstruction;
            log({actor: 'user', kind: 'user', message: adjustMsg}, emitLog);
            showFeedbackThinking(texts);
            if (compactPromptInput) {
                compactPromptInput.value = '';
            }
        }

        // Approving the plan is a user action too — surface it as a coherent turn
        // (no « », concise) so the thread never goes silent on approve.
        if (action === 'accept') {
            state.planEverReviewed = true;
            log({
                actor: 'user',
                kind: 'success',
                message: texts.courseai_log_user_approved || 'You approved the plan',
            }, emitLog);
            showFeedbackThinking(texts);
        }

        // Plan actions travel as ActionIntents: a bare approve is action
        // 'accept'; free text is action 'feedback'. Image curation no longer
        // rides on accept — it is its own discard_image/replan_image action.
        const pendingAction = {
            action: action === 'accept' ? 'accept' : 'feedback',
            instruction,
        };

        if (action === 'accept') {
            // PRESERVE detailedTotal BEFORE any state changes or stream opening
            state.phase4TotalActivities = state.detailedTotal || 0;

            const keptImages = Object.keys(state.selectedDetailedImages)
                .filter((id) => state.selectedDetailedImages[id] !== false).length;
            state.completionStats = {
                units: state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0,
                activities: state.totalActivities || state.detailedTotal || 0,
                images: keptImages,
            };
        }

        const feedbackResponse = await sendPlanningFeedback({
            recordid: state.sessionid,
            pendingAction,
        });

        if (!feedbackResponse || !feedbackResponse.success) {
            throw new Error(feedbackResponse?.message || texts.courseai_error_send_feedback);
        }

        if (action === 'accept') {
            stepsUi.setStepState('planning', 'done');
            stepsUi.setStepState('generating', 'active');
            state.currentStage = 'generating';

            // Initialize content generation tracking
            state.contentGenerationStarted = 0;
            state.contentGenerationCurrent = 0;

            stepsUi.setProgress(0);
            stepsUi.updateFlowNav();
        }

        // Sync chips to compact chat so they remain visible during phase 3 streaming.
        // For 'adjust' actions the text is kept so the user sees what they submitted.
        if (action === 'accept') {
            planningUi.syncCompactChatState();
        }

        const streamMode = action === 'accept' ? 'generating' : 'planning';
        // keepPlan: an 'adjust' resumes the existing plan to apply free-text feedback,
        // so preserve the rendered preview and let the reconciler diff against it
        // (only changed rows animate). 'accept' transitions to generation → full reset.
        const keepPlan = action !== 'accept';
        streamManager.openSSEStream(state.streamingurl, 0, streamMode, keepPlan);
    } catch (error) {
        // The stream never opened — clear the live indicator so it does not
        // linger after the error modal closes (no lifecycle event will arrive).
        hideWorkingIndicator();
        await Notification.exception(error);
    } finally {
        if (btnApprove) {
            btnApprove.disabled = false;
        }
    }
};
