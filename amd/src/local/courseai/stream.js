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
 * SSE stream manager for Course AI.
 *
 * Thin orchestrator: builds a per-stream context object and delegates
 * connection lifecycle to stream/connection.js and event routing to
 * stream/handlers.js.  All tracker/progress/normalization logic lives
 * in the stream/* submodules.
 *
 * Public API (consumed by courseai.js):
 *   createStreamManager(deps) → { closeStream, openSSEStream }
 *   openSSEStream(url, retryAttempt, streamMode)
 *
 * @module     local_coursegen/local/courseai/stream
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setCompactChatState} from './ui-planning';
import {localizeMessage} from './i18n';
import {ensureStreamContentVisible, showStreamBar, hideStreamBar} from './stream/bar';
import {normalizeText, extractActivityFromStatus, isActivityDoneStatus, getActivityLabel} from './stream/normalize';
import {
    createGenerationTracker,
    markAllTrackerActivitiesDone,
    updateTrackerActivityStatusByCoordinates,
    updateTrackerImageProgress,
    getTrackerImagesProgress,
    updateGeneratingProgressFromStructuredState,
} from './stream/tracker';
import {syncTrackerFromStatus as syncTrackerFromStatusFn} from './stream/tracker-sync';
import {renderGenerationTracker} from './stream/tracker-renderer';
import {setCompletionStatsFromGeneratedResult as setCompletionStats} from './stream/completion';
import {getOrCreateRoundChecklist} from './stream/checklist';
import {openConnection} from './stream/connection';
import {showWorkingIndicator} from './ui/feedback-progress';
import {resetTranscript} from './ui/plan-transcript';

// Module-level variable to preserve phase 4 total activities.
// This survives state resets that happen during stream opening.
let preservedPhase4Total = 0;

/**
 * Create stream manager.
 *
 * @param {Object} deps
 * @returns {{closeStream: Function, openSSEStream: Function}}
 */
export const createStreamManager = (deps) => {
    const {
        state, elements, stepsUi, planningUi, detailedUi,
        proposalsUi, renderPlanMarkdown, createCourseFromSession, texts,
        emitLog,
    } = deps;

    const {
        prvHeaderSub, prvHeaderTitle, prvLiveNote,
        pcSubtitle, pcTitle, typingCursor, planningSpinner,
        pcStep, planningProgressCard, pcToggleRow, pcDetailsPanel, pcChevron,
    } = elements;

    // Bound helpers that close over per-manager state.
    const renderTracker = () => renderGenerationTracker(state, pcDetailsPanel, texts, getActivityLabel);
    const markAllDone = () => markAllTrackerActivitiesDone(state, renderTracker);
    const updateProgress = () => updateGeneratingProgressFromStructuredState(state, stepsUi);
    const updateCoords = (si, ai, status) => updateTrackerActivityStatusByCoordinates(state, si, ai, status);
    const updateImgProgress = (si, ai, done, total) => updateTrackerImageProgress(state, si, ai, done, total);
    const getImgProgress = () => getTrackerImagesProgress(state);
    const setCompletionStatsFromGeneratedResult = (result) => setCompletionStats(state, result);
    const syncTrackerFromStatus = (statusText) => syncTrackerFromStatusFn(
        state, statusText, normalizeText, extractActivityFromStatus, isActivityDoneStatus, renderTracker
    );

    const closeStream = () => {
        if (!state.sseSource) {
            return;
        }
        try {
            state.sseSource.close();
        } catch (e) {
            // Ignore stream close errors.
        }
        state.sseSource = null;
    };

    const openSSEStream = (streamUrl, retryAttempt = 0, streamMode = 'planning', keepPlan = false) => {
        if (!streamUrl) {
            throw new Error(texts.courseai_error_stream_url);
        }
        closeStream();

        // Keep compact chat disabled for the whole active stream lifecycle. EXCEPTION:
        // a keepPlan re-stream is an ACTION on an already-reviewed plan (reorder, replan,
        // add, delete). There the decision card owns the bottom slot and a working
        // indicator already gives feedback — showing the composer here (even disabled,
        // display:block) flashes it into view for the ~200ms until review_needed re-shows
        // the decision card. So keep it HIDDEN for keepPlan re-streams; only the initial
        // planning stream shows the disabled composer (with Stop). 'hidden' does not set
        // isStreaming, so set it explicitly to keep drag/actions blocked during the run.
        setCompactChatState(deps, keepPlan ? 'hidden' : 'disabled');
        if (keepPlan && state) {
            state.isStreaming = true;
        }

        if (typeof deps.onStreamStart === 'function') {
            deps.onStreamStart();
        }

        // Clear stale proposals so they do not linger when a stream resumes.
        if (proposalsUi && typeof proposalsUi.clear === 'function') {
            proposalsUi.clear();
        }

        // Only reset planning UI on the first attempt (not on stale-done retries).
        if (retryAttempt === 0) {
            if (streamMode !== 'generating') {
                preservedPhase4Total = 0;
            }
            const savedLatestInitialSections = Array.isArray(state.latestInitialSections)
                ? state.latestInitialSections : [];
            const savedPhase4Total = state.phase4TotalActivities || 0;

            stepsUi.resetPlanningState({showLoading: streamMode !== 'generating' && !keepPlan, keepPlan});

            // Fresh planning round → clear the live LEFT transcript so a new plan
            // never stacks on the previous one. keepPlan adjusts keep the existing
            // transcript (review_needed rebuilds it from the authoritative plan).
            if (streamMode !== 'generating' && !keepPlan) {
                resetTranscript();
            }

            // Suppress the centered generic spinner immediately for planning streams.
            // The review card with skeleton rows will render as the first section event arrives.
            if (streamMode !== 'generating') {
                ensureStreamContentVisible();
            }

            if (savedLatestInitialSections.length > 0) {
                state.latestInitialSections = savedLatestInitialSections;
            }

            if (streamMode === 'generating') {
                ensureStreamContentVisible();
                // Generation reuses the SAME plan cards as planning (#prvSections) as a
                // read-only live progress view (per-activity spinner→check), so the whole
                // wizard stays unified. Show that view and keep the old progress card hidden.
                document.body.classList.add('cg-generating');
                // Hard-disable drag-and-drop for the whole generation phase: reordering is
                // ONLY for planning. The dnd wirer cancels drags while isStreaming is true,
                // but the composer-hidden gate skips the 'disabled' branch that normally
                // sets it — so set it explicitly here.
                state.isStreaming = true;
                // The header carried the planning-review DONE check; generation is still
                // in progress, so put the header back to its spinner until it completes.
                const prvHeaderEl = document.getElementById('prvHeader');
                const prvSpinEl = document.getElementById('prvSpinnerIcon');
                const prvCheckEl = document.getElementById('prvCheckIcon');
                if (prvHeaderEl) {
                    prvHeaderEl.classList.remove('prv-header--done');
                }
                if (prvSpinEl) {
                    prvSpinEl.style.display = '';
                }
                if (prvCheckEl) {
                    prvCheckEl.style.display = 'none';
                }
                // Stable generation header (the per-activity narration is suppressed —
                // handleStatus — so it never desyncs from the cards). The cards are the
                // live per-activity progress; the header just states the phase.
                if (prvHeaderTitle) {
                    prvHeaderTitle.textContent = texts.courseai_course_creating;
                }
                if (prvHeaderSub) {
                    prvHeaderSub.textContent = texts.courseai_course_creating_subtitle;
                }
                // Sync the LEFT working indicator with the CENTER header. The Accept action
                // left it on "Analyzing your request…"; during generation the center shows
                // "Generating course content…" and suppressRaw stops per-activity narration
                // from updating the left one — so it would sit frozen on the stale accept
                // text while the center says something else (a jarring desync). Set it to the
                // SAME message the center subtitle shows so both panels tell one story. The
                // composer is hidden here (plan approved), so it anchors in #cgWorkingSlot.
                showWorkingIndicator(texts, texts.courseai_course_creating_subtitle);
                // The plan preview cards live inside #planReviewCard (hidden once a stream
                // starts); show it as the live progress view and keep the old progress
                // card hidden. switchPlanMode keeps the detailed sub-view active.
                const reviewCard = elements.planReviewCard || document.getElementById('planReviewCard');
                if (reviewCard) {
                    reviewCard.style.display = '';
                }
                if (typeof stepsUi.switchPlanMode === 'function') {
                    stepsUi.switchPlanMode('detailed');
                }
                if (planningProgressCard) {
                    planningProgressCard.style.display = 'none';
                }
                if (pcToggleRow) {
                    pcToggleRow.style.display = 'flex';
                }
                state.planDetailsOpen = true;
                if (pcDetailsPanel) {
                    pcDetailsPanel.style.display = 'block';
                }
                if (pcChevron) {
                    pcChevron.style.transform = 'rotate(90deg)';
                }
                if (pcStep) {
                    pcStep.textContent = texts.courseai_state_completed;
                }
                if (pcTitle) {
                    pcTitle.textContent = texts.courseai_course_creating;
                }
                if (pcSubtitle) {
                    pcSubtitle.textContent = texts.courseai_course_creating_subtitle;
                }
                state.generationTracker = createGenerationTracker(state, texts);
                state.structuredActivityProgress = false;
                state.activityProgressTotal = 0;
                state.activityProgressStarted = 0;
                state.activityProgressDone = 0;
                // keepPlan preserved the review cards intact (full descriptions) — just
                // mark the per-activity status on them (all pending, spinner→check as
                // each is created in Moodle).
                renderTracker();
            }

            // RESTORE phase4TotalActivities AFTER reset
            if (savedPhase4Total > 0) {
                state.phase4TotalActivities = savedPhase4Total;
                preservedPhase4Total = savedPhase4Total;
            }
        }

        // Show thin stream bar on the review card for planning streams.
        if (streamMode !== 'generating') {
            showStreamBar();
        }

        // Build the per-stream context object carried into all handlers.
        const ctx = {
            state,
            elements,
            stepsUi,
            planningUi,
            detailedUi,
            proposalsUi,
            renderPlanMarkdown,
            createCourseFromSession,
            texts,
            deps,
            closeStream,
            ensureStreamContentVisible,
            hideStreamBar,
            setCompactChatState,
            localizeMessage,
            syncTrackerFromStatus,
            renderTracker,
            markAllDone,
            updateProgress,
            updateTrackerActivityStatusByCoordinates: updateCoords,
            updateTrackerImageProgress: updateImgProgress,
            getTrackerImagesProgress: getImgProgress,
            setCompletionStatsFromGeneratedResult,
            getOrCreateRoundChecklist,
            streamMode,
            keepPlan,
            retryAttempt,
            openSSEStream,
            preservedPhase4Total: () => preservedPhase4Total,
            prvHeaderSub,
            prvHeaderTitle,
            prvLiveNote,
            pcSubtitle,
            pcTitle,
            typingCursor,
            planningSpinner,
            pcStep,
            // Per-attempt mutable flags — handlers write ctx.flags.contentReceived = true.
            flags: {contentReceived: false},
            emitLog: typeof emitLog === 'function' ? emitLog : () => undefined,
            onStreamEnd: typeof deps.onStreamEnd === 'function' ? deps.onStreamEnd : () => undefined,
        };

        openConnection(streamUrl, retryAttempt, ctx, openSSEStream);
    };

    return {
        closeStream,
        openSSEStream,
    };
};
