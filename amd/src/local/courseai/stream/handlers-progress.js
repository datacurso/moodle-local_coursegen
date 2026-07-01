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
 * SSE structured progress event handlers:
 * activity_progress_init/start/done/failed and image_progress_init/tick/done.
 *
 * Each handler signature: (data, ctx) => void
 *
 * @module     local_coursegen/local/courseai/stream/handlers-progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setTrackerFlatStatus} from 'local_coursegen/local/courseai/stream/tracker';
import {hideWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

/**
 * When every activity has been generated, resolve the header spinner to a check
 * and drop the live "working" indicator, so the header/left stay in sync with the
 * all-checked cards (no lingering spinner or stale status line).
 *
 * @param {Object} state
 * @returns {void}
 */
const resolveGenerationHeaderIfDone = (state) => {
    const total = state.activityProgressTotal || 0;
    if (total <= 0 || (state.activityProgressDone || 0) < total) {
        return;
    }
    const hdr = document.getElementById('prvHeader');
    const spin = document.getElementById('prvSpinnerIcon');
    const chk = document.getElementById('prvCheckIcon');
    if (hdr) {
        hdr.classList.add('prv-header--done');
    }
    if (spin) {
        spin.style.display = 'none';
    }
    if (chk) {
        chk.style.display = '';
    }
    hideWorkingIndicator();
};

/**
 * Handle 'activity_progress_init': switch to structured progress mode.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleActivityProgressInit = (data, ctx) => {
    const {state, stepsUi} = ctx;
    state.structuredActivityProgress = true;
    state.activityProgressTotal = Math.max(0, Number(data.total) || 0);
    state.activityProgressStarted = 0;
    state.activityProgressDone = 0;
    if (state.currentStage === 'generating' && state.activityProgressTotal > 0) {
        stepsUi.setProgress(0);
    }
};

/**
 * Handle 'activity_progress_start': mark activity in_progress and update bar.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleActivityProgressStart = (data, ctx) => {
    setTrackerFlatStatus(ctx.state, Number(data.index), 'in_progress');
    ctx.state.activityProgressStarted = (ctx.state.activityProgressStarted || 0) + 1;
    ctx.updateProgress();
    ctx.renderTracker();
};

/**
 * Handle 'activity_progress_done': mark activity done and update bar.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleActivityProgressDone = (data, ctx) => {
    setTrackerFlatStatus(ctx.state, Number(data.index), 'done');
    ctx.state.activityProgressDone = (ctx.state.activityProgressDone || 0) + 1;
    ctx.updateProgress();
    ctx.renderTracker();
    resolveGenerationHeaderIfDone(ctx.state);
};

/**
 * Handle 'activity_progress_failed': treat as done for progress purposes.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleActivityProgressFailed = (data, ctx) => {
    setTrackerFlatStatus(ctx.state, Number(data.index), 'done');
    ctx.state.activityProgressDone = (ctx.state.activityProgressDone || 0) + 1;
    ctx.updateProgress();
    ctx.renderTracker();
    resolveGenerationHeaderIfDone(ctx.state);
};

/**
 * Handle 'image_progress_init': seed per-activity image totals.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleImageProgressInit = (data, ctx) => {
    const activities = Array.isArray(data.activities) ? data.activities : [];
    activities.forEach((item) => {
        ctx.updateTrackerImageProgress(
            Number(item.section_index) || 0,
            Number(item.activity_index) || 0,
            Number(item.done) || 0,
            Number(item.total) || 0
        );
    });
    const imageTotals = ctx.getTrackerImagesProgress();
    ctx.state.imageProgressDone = imageTotals.done;
    ctx.state.imageProgressTotal = imageTotals.total;
    ctx.updateProgress();
    ctx.renderTracker();
};

/**
 * Handle 'image_progress_tick': update a single activity's image count.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleImageProgressTick = (data, ctx) => {
    ctx.updateTrackerImageProgress(
        Number(data.section_index) || 0,
        Number(data.activity_index) || 0,
        Number(data.done) || 0,
        Number(data.total) || 0
    );
    const imageTotals = ctx.getTrackerImagesProgress();
    ctx.state.imageProgressDone = imageTotals.done;
    ctx.state.imageProgressTotal = imageTotals.total;
    ctx.updateProgress();
    ctx.renderTracker();
};

/**
 * Handle 'image_progress_done': finalize image progress.
 *
 * @param {Object} data
 * @param {Object} ctx
 */
export const handleImageProgressDone = (data, ctx) => {
    ctx.state.imageProgressDone = ctx.state.imageProgressTotal || 0;
    ctx.updateProgress();
};
