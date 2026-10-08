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
 * Turns one decoded template-generation stream event into a DOM update:
 * which activity row lights up, which item of the progress list is added or
 * closed, and which phase label shows. generation_watch.js decodes the stream
 * and calls this for each event; generation_stream.js owns the phase label itself and passes
 * `paintStage` in rather than this module importing it, so the two never
 * import each other.
 *
 * @module     local_coursegen/local/courseai/template/generation_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    addActivity,
    closeActivity,
    openChecklist,
    settleChecklist,
} from 'local_coursegen/local/courseai/template/generation_checklist';
import {
    createSeen,
    failureOutcome,
    normalizeEvent,
    waitingSeconds,
} from 'local_coursegen/local/courseai/template/agent_events';
import {clearWaiting, setPaused, showWaiting} from 'local_coursegen/local/courseai/template/generation_waiting';
import {showToolCall} from 'local_coursegen/local/courseai/template/agent_steps';

const seen = createSeen();

/** The reason the service gives for an activity the run left as it was. */
const REASON_NOT_CHANGED = 'not_changed';

/**
 * Forget the events already shown, when a run starts or a reloaded page repaints it.
 */
export const resetSeen = () => {
    seen.reset();
};

/** The status classes the shared generation stylesheet reacts to. */
export const STATUS_CLASS = {
    pending: 'cg-gen-pending',
    running: 'cg-gen-active',
    done: 'cg-gen-done',
    skipped: 'cg-gen-skipped',
};
export const ALL_STATUS_CLASSES = Object.values(STATUS_CLASS);

/**
 * Mark one activity row with the state its generation is in.
 *
 * @param {string} uid
 * @param {string} status A key of STATUS_CLASS.
 */
export const markRow = (uid, status) => {
    const row = document.querySelector(`[data-generation-uid="${uid}"]`);
    if (!row) {
        return;
    }
    row.classList.remove(...ALL_STATUS_CLASSES);
    row.classList.add(STATUS_CLASS[status] || STATUS_CLASS.pending);
};

/**
 * Take the status off one row, unless its activity was written: that row keeps its check, as in free mode.
 *
 * @param {Element} row
 */
const settleRow = (row) => {
    if (row.classList.contains(STATUS_CLASS.done)) {
        return;
    }
    row.classList.remove(...ALL_STATUS_CLASSES);
};

/**
 * Settle the activity rows once the run has ended: the written ones keep their check, no row keeps a spinner.
 */
export const settleRows = () => {
    const rows = document.querySelectorAll('[data-generation-uid]');
    for (const row of rows) {
        settleRow(row);
    }
};

/**
 * The status a row takes when its activity is closed: a check when it was written, none when it failed or was left as it was.
 *
 * @param {Object} data An activity_progress_done or activity_progress_failed event.
 * @returns {string} A key of STATUS_CLASS.
 */
const closedStatus = (data) => {
    if (data.type === 'activity_progress_failed') {
        return 'skipped';
    }
    return 'done';
};

/**
 * Reset the progress counters for a phase that is starting over.
 *
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Object} data
 */
const resetProgress = (progress, data) => {
    progress.total = Math.max(0, Number(data.total) || 0);
    progress.done = 0;
};

/**
 * Queue every activity the run announces, so its row spins from the first event until it ends.
 *
 * @param {Object} data An activity_progress_init event: the activities the AI may work on.
 */
const queueAnnouncedRows = (data) => {
    let announced = data.activities;
    if (!Array.isArray(announced)) {
        announced = [];
    }
    for (const activity of announced) {
        const row = normalizeEvent(activity);
        if (row.uid !== '') {
            markRow(row.uid, 'pending');
        }
    }
};

/**
 * A status of the run: only the one that counts the seconds of a call in flight is shown.
 *
 * @param {Object} data
 * @returns {string} ''
 */
const showStatus = (data) => {
    const seconds = waitingSeconds(data);
    if (seconds >= 0) {
        // A call in flight means the run went on after an answer, so the spinners turn again.
        setPaused(false);
        showWaiting(seconds);
    }
    return '';
};

/**
 * One activity starts being written: open the progress list the first time, light its row and add its item.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done, opened} counters.
 * @returns {string} ''
 */
const startActivity = (data, progress) => {
    if (!progress.opened) {
        progress.opened = true;
        openChecklist(progress);
    }
    markRow(data.uid, 'running');
    addActivity(data);
    return '';
};

/**
 * One activity's generation failed or finished; count it and, once every
 * activity is accounted for, move the header on to the next phase.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} paintStage Shows one phase label, by key.
 * @returns {string} ''
 */
const finishActivity = (data, progress, paintStage) => {
    const unchanged = data.type === 'activity_progress_failed' && data.reason === REASON_NOT_CHANGED;
    // A failed activity is still counted and still stops looking "in progress", but it gets no check.
    const status = closedStatus(data);
    markRow(data.uid, status);
    progress.done += 1;
    if (progress.total < progress.done) {
        progress.total = progress.done;
    }
    closeActivity(data.uid, progress, unchanged);
    if (progress.total > 0 && progress.done >= progress.total) {
        paintStage('saving');
    }
    return '';
};

/** One handler per stream event type, keyed the way the server names them. */
const EVENT_HANDLERS = {
    template_stage: (data, progress, paintStage) => {
        paintStage(data.stage);
        return '';
    },
    activity_progress_init: (data, progress, paintStage) => {
        // A new round after a change request reopens activities and may ask again with the same ids.
        seen.reset();
        resetProgress(progress, data);
        queueAnnouncedRows(data);
        progress.opened = true;
        openChecklist(progress);
        paintStage('activities');
        return '';
    },
    activity_progress_start: startActivity,
    status: showStatus,
    token: () => '',
    section: () => '',
    tool_call: (data) => {
        showToolCall(data);
        return '';
    },
    tool_result: () => '',
    question: () => 'question',
    activity_progress_done: finishActivity,
    activity_progress_failed: finishActivity,
    completed: (data, progress) => {
        settleChecklist(progress);
        settleRows();
        return 'completed';
    },
    failed: (data) => failureOutcome(data),
};

/**
 * Any event of the run other than a status ends the wait that the last tick showed, and a question also pauses
 * the spinners until the next event shows the run went on.
 *
 * @param {Object} event
 */
const settleWaiting = (event) => {
    if (event.type === 'status') {
        return;
    }
    clearWaiting();
    const paused = event.type === 'question';
    setPaused(paused);
};

/**
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} paintStage Shows one phase label, by key.
 * @returns {string} '' to keep listening, otherwise 'question', 'retry', 'completed' or 'failed'.
 */
export const applyEvent = (data, progress, paintStage) => {
    const event = normalizeEvent(data);
    if (!seen.accept(event)) {
        return '';
    }
    if (!Object.prototype.hasOwnProperty.call(EVENT_HANDLERS, event.type)) {
        return '';
    }
    const handler = EVENT_HANDLERS[event.type];
    settleWaiting(event);
    return handler(event, progress, paintStage);
};
