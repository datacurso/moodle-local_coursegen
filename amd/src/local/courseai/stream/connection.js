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
 * EventSource lifecycle management for the SSE stream.
 *
 * Knows nothing about event types — delegates all routing to routeEvent.
 *
 * @module     local_coursegen/local/courseai/stream/connection
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {routeEvent} from './handlers';
import {
    hideWorkingIndicator,
    showWorkingIndicator,
} from 'local_coursegen/local/courseai/ui/feedback-progress';

/** Maximum number of stale-done retries before giving up. */
const MAX_STALE_RETRIES = 3;

/** Delay in milliseconds between stale-done retries. */
const STALE_RETRY_DELAY_MS = 2000;

/**
 * Open an EventSource connection and wire message, done, and error listeners.
 *
 * The ctx.flags.contentReceived flag is reset to false here and set to true
 * by any structural content event handler (section, activity, token, etc.).
 * The 'done' listener uses it to detect stale-done races and retry.
 *
 * @param {string}   streamUrl      SSE endpoint URL
 * @param {number}   retryAttempt   Current retry count (0 on first open)
 * @param {Object}   ctx            Per-stream context object (see handlers.js for shape)
 * @param {Function} openSSEStream  The outer openSSEStream function (for stale-done retry)
 */
export const openConnection = (streamUrl, retryAttempt, ctx, openSSEStream) => {
    const {
        state, detailedUi, streamMode,
        setCompactChatState, deps,
        typingCursor, planningSpinner, pcStep, pcSubtitle, texts,
    } = ctx;

    // Reset per-attempt content flag.
    ctx.flags.contentReceived = false;

    state.sseSource = new EventSource(streamUrl);

    // Show a working indicator the instant the stream opens, so the left panel is
    // NEVER blank between the prompt turn and the first server status (the first
    // status can take a while). handleStatus updates this SAME entry in place as
    // statuses arrive, so the message is continuous — it only changes when the
    // next one is ready, and is cleared only when real content lands. Skip if an
    // indicator is already present (e.g. feedback's "Analyzing your request…").
    if (!document.getElementById('cgFeedbackThinking')) {
        showWorkingIndicator(texts);
    }

    state.sseSource.addEventListener('message', async(event) => {
        let data = null;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            return;
        }
        await routeEvent(data, ctx);
    });

    state.sseSource.addEventListener('done', () => {
        ctx.closeStream();

        // Stale-done guard: a 'done' with no content means the previous stream's
        // buffered 'done' arrived before the new content (Pausar + Regenerar race).
        // Retry automatically up to MAX_STALE_RETRIES times.
        if (!ctx.flags.contentReceived && retryAttempt < MAX_STALE_RETRIES) {
            setTimeout(
                () => openSSEStream(streamUrl, retryAttempt + 1, streamMode),
                STALE_RETRY_DELAY_MS
            );
            return;
        }

        // Terminal 'done' (no retry): drop the live working indicator so it can
        // never linger when the stream closes without a lifecycle event.
        hideWorkingIndicator();
        if (typeof ctx.hideStreamBar === 'function') {
            ctx.hideStreamBar();
        }
        if (typingCursor) {
            typingCursor.classList.add('hidden');
        }
        if (streamMode === 'generating') {
            ctx.markAllDone();
        }
        if (planningSpinner) {
            planningSpinner.classList.add('done');
        }
        // Safety net: enable action controls in case review_needed didn't cover newly created controls.
        if (typeof detailedUi.enableAllActionControls === 'function') {
            detailedUi.enableAllActionControls();
        }
        // Stream completed normally — keep chat disabled during generating phase.
        if (streamMode !== 'generating') {
            state.isStreaming = false;
            setCompactChatState(deps, 'enabled');
        }
    });

    state.sseSource.onerror = () => {
        state.isStreaming = false;
        // Connection dropped — clear the live working indicator so it does not
        // stay pinned forever on abnormal stream termination.
        hideWorkingIndicator();
        if (typeof ctx.hideStreamBar === 'function') {
            ctx.hideStreamBar();
        }
        if (typingCursor) {
            typingCursor.classList.add('hidden');
        }
        if (planningSpinner) {
            planningSpinner.classList.add('done');
        }
        if (pcStep) {
            pcStep.textContent = texts.courseai_state_error;
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = texts.courseai_error_connection;
        }
        // Connection error — re-enable compact chat for retry.
        setCompactChatState(deps, 'enabled');
    };
};
