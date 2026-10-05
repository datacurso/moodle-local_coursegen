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

import {addActivity, closeActivity, openChecklist} from 'local_coursegen/local/courseai/template/generation_checklist';

/** The status classes the shared generation stylesheet reacts to. */
export const STATUS_CLASS = {
    pending: 'cg-gen-pending',
    running: 'cg-gen-active',
    done: 'cg-gen-done',
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
 * One activity's generation failed or finished; count it and, once every
 * activity is accounted for, move the header on to the next phase.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} paintStage Shows one phase label, by key.
 * @returns {string} ''
 */
const finishActivity = (data, progress, paintStage) => {
    // A failed activity is still counted and still stops looking
    // "in progress": the run itself then fails, which is what the
    // professor is told about.
    markRow(data.uid, 'done');
    progress.done += 1;
    closeActivity(data.uid, progress);
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
    review_needed: () => 'review',
    ask_user: () => 'question',
    activity_progress_init: (data, progress, paintStage) => {
        resetProgress(progress, data);
        openChecklist(progress);
        paintStage('activities');
        return '';
    },
    activity_progress_start: (data) => {
        markRow(data.uid, 'running');
        addActivity(data);
        return '';
    },
    activity_progress_done: finishActivity,
    activity_progress_failed: finishActivity,
    completed: () => 'completed',
    failed: () => 'failed',
};

/**
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} paintStage Shows one phase label, by key.
 * @returns {string} '' to keep listening, otherwise a pause or terminal outcome.
 */
export const applyEvent = (data, progress, paintStage) => {
    const handler = EVENT_HANDLERS[data.type];
    if (!handler) {
        return '';
    }
    return handler(data, progress, paintStage);
};
