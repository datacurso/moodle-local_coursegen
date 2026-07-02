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
 * Stop/Resume execution control for Course AI streams.
 *
 * Provides Stop and Resume buttons in the compact toolbar.
 * Stop closes the active SSE stream and freezes the UI (removes loading states,
 * never removes rendered content).
 * Resume reopens the SSE stream from the LangGraph checkpoint.
 *
 * @module     local_coursegen/local/courseai/actions/execution-control
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove all loading/in-flight UI without touching rendered plan content.
 *
 * @param {Object} elements
 */
const freezeLoaders = (elements) => {
    const planningLoading = document.getElementById('planningLoading');
    if (planningLoading) {
        planningLoading.style.display = 'none';
    }

    const streamBar = document.querySelector('.cg-stream-bar');
    if (streamBar) {
        streamBar.remove();
    }

    document.querySelectorAll('.dp-item-regenerating').forEach((el) => {
        el.classList.remove('dp-item-regenerating');
    });

    document.querySelectorAll('.cg-skeleton-wrap').forEach((el) => {
        el.remove();
    });

    document.querySelectorAll('.cg-skeleton').forEach((el) => {
        el.remove();
    });

    if (elements.typingCursor) {
        elements.typingCursor.classList.add('hidden');
    }

    document.querySelectorAll('.planning-spinner').forEach((el) => {
        el.style.display = 'none';
    });

    document.body.classList.add('cg-execution-stopped');
};

/**
 * Show the Stop button and hide the Resume button.
 *
 * @param {Object} elements
 */
const showStop = (elements) => {
    if (elements.btnStopExec) {
        elements.btnStopExec.style.display = '';
    }
    if (elements.btnResumeExec) {
        elements.btnResumeExec.style.display = 'none';
    }
};

/**
 * Hide both Stop and Resume buttons (stream ended normally).
 *
 * @param {Object} elements
 */
const hideControls = (elements) => {
    if (elements.btnStopExec) {
        elements.btnStopExec.style.display = 'none';
    }
    if (elements.btnResumeExec) {
        elements.btnResumeExec.style.display = 'none';
    }
};

/**
 * Handle Stop button click.
 *
 * @param {Object} state
 * @param {Object} elements
 * @param {Object} streamManager
 * @param {Object} texts
 * @param {Function} emitLog
 */
const handleStop = (state, elements, streamManager, texts, emitLog) => {
    streamManager.closeStream();
    state.isStreaming = false;
    state.stopped = true;
    freezeLoaders(elements);

    if (elements.btnStopExec) {
        elements.btnStopExec.style.display = 'none';
    }
    if (elements.btnResumeExec) {
        elements.btnResumeExec.style.display = '';
    }

    emitLog({
        actor: 'user',
        kind: 'neutral',
        message: texts.courseai_btn_stop || 'Stop',
    });
};

/**
 * Handle Resume button click.
 *
 * @param {Object} state
 * @param {Object} elements
 * @param {Object} streamManager
 * @param {Object} texts
 * @param {Function} emitLog
 */
const handleResume = (state, elements, streamManager, texts, emitLog) => {
    state.stopped = false;
    document.body.classList.remove('cg-execution-stopped');

    if (elements.btnResumeExec) {
        elements.btnResumeExec.style.display = 'none';
    }
    if (elements.btnStopExec) {
        elements.btnStopExec.style.display = '';
    }

    const mode = state.currentStage === 'generating' ? 'generating' : 'planning';
    streamManager.openSSEStream(state.streamingurl, 0, mode, true);

    emitLog({
        actor: 'user',
        kind: 'neutral',
        message: texts.courseai_btn_resume || 'Resume',
    });
};

/**
 * Create execution controls (Stop/Resume) for the compact toolbar.
 *
 * @param {Object} deps
 * @param {Object} deps.state
 * @param {Object} deps.elements
 * @param {Object} deps.streamManager
 * @param {Object} deps.texts
 * @param {Function} deps.emitLog
 * @returns {{bindEvents: Function, showStop: Function, hideControls: Function}}
 */
export const createExecutionControls = (deps) => {
    const {state, elements, streamManager, texts, emitLog} = deps;

    const bindEvents = () => {
        if (elements.btnStopExec) {
            elements.btnStopExec.addEventListener('click', () => {
                handleStop(state, elements, streamManager, texts, emitLog);
            });
        }
        if (elements.btnResumeExec) {
            elements.btnResumeExec.addEventListener('click', () => {
                handleResume(state, elements, streamManager, texts, emitLog);
            });
        }
    };

    return {
        bindEvents,
        showStop: () => showStop(elements),
        hideControls: () => hideControls(elements),
    };
};
