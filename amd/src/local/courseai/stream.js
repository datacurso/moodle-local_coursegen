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

        // Keep compact chat disabled for the whole active stream lifecycle.
        setCompactChatState(deps, 'disabled');

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
                if (planningProgressCard) {
                    planningProgressCard.style.display = '';
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
