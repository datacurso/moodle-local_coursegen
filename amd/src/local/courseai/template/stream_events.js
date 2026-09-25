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
 * Decoding one SSE event of a course-from-template generation into an effect
 * on the page: which activity row lights up, which phase label shows, which
 * checklist row fills in.
 *
 * Split out of generation_stream.js (which owns opening/watching the stream
 * itself) so that module stays about the connection, and this one about what
 * each event on it means.
 *
 * @module     local_coursegen/local/courseai/template/stream_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {renderActivityPlan} from 'local_coursegen/local/courseai/template/plan_review';
import {
    addPlanPart,
    addPlanSummary,
    finishChecklistRow,
    openChecklist,
    startPlanEntry,
} from 'local_coursegen/local/courseai/template/thread';
import {showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

/** The status classes the shared generation stylesheet reacts to. */
export const STATUS_CLASS = {
    pending: 'cg-gen-pending',
    running: 'cg-gen-active',
    done: 'cg-gen-done',
};
export const ALL_STATUS_CLASSES = Object.values(STATUS_CLASS);

/** Phase keys the service reports, plus the two this module owns. */
const STAGE_STRINGS = {
    planning: 'courseai_template_stage_planning',
    reviewing: 'courseai_template_stage_reviewing',
    style: 'courseai_template_stage_style',
    activities: 'courseai_template_stage_activities',
    activity_images: 'courseai_template_stage_activity_images',
    section_images: 'courseai_template_stage_section_images',
    saving: 'courseai_template_stage_saving',
    connecting: 'courseai_template_stage_connecting',
    building: 'courseai_template_stage_building',
};
const TITLE_STRING = 'courseai_template_generating_title';

let labels = null;

/**
 * The localised header strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by phase key, plus `title`.
 */
export const getLabels = async() => {
    if (!labels) {
        const keys = Object.keys(STAGE_STRINGS);
        const values = await getStrings([
            ...keys.map((key) => ({key: STAGE_STRINGS[key], component: 'local_coursegen'})),
            {key: TITLE_STRING, component: 'local_coursegen'},
        ]);
        labels = {title: values[keys.length]};
        keys.forEach((key, index) => {
            labels[key] = values[index];
        });
    }
    return labels;
};

/** Every activity row the AI is going to generate. */
export const generatedRows = () => document.querySelectorAll('[data-generation-cmid]');

/**
 * Mark one activity row with the state its generation is in.
 *
 * @param {number|string} cmid
 * @param {string} status A key of STATUS_CLASS.
 */
export const markRow = (cmid, status) => {
    const row = document.querySelector(`[data-generation-cmid="${cmid}"]`);
    if (!row) {
        return;
    }
    row.classList.remove(...ALL_STATUS_CLASSES);
    row.classList.add(STATUS_CLASS[status] || STATUS_CLASS.pending);
};

/**
 * Show one phase label in the header's subtitle.
 *
 * @param {string} key
 */
export const paintStage = async(key) => {
    const text = (await getLabels())[key];
    if (!text) {
        return;
    }
    const stage = document.getElementById('tplGenStage');
    if (stage) {
        stage.textContent = text;
    }
    // Free mode keeps both panels on the same sentence, updating one indicator
    // in place rather than stacking an entry per phase. showWorkingIndicator
    // does exactly that, and pins itself to the bottom slot while the composer
    // is away - which here is the whole generation.
    showWorkingIndicator({}, text);
};

/**
 * @param {Object} data
 * @returns {string}
 */
const handleTemplateStage = (data) => {
    paintStage(data.stage);
    return '';
};

/**
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string}
 */
const handlePlanProgressInit = (data, progress) => {
    progress.total = Math.max(0, Number(data.total) || 0);
    progress.done = 0;
    paintStage('planning');
    openChecklist(data.sections);
    return '';
};

/**
 * @param {Object} data
 * @returns {string}
 */
const handlePlanProgressStart = (data) => {
    markRow(data.cmid, 'running');
    startPlanEntry(data);
    return '';
};

/**
 * @param {Object} data
 * @returns {string}
 */
const handlePlanProgressSummary = (data) => {
    addPlanSummary(data);
    return '';
};

/**
 * @param {Object} data
 * @returns {string}
 */
const handlePlanProgressPart = (data) => {
    addPlanPart(data);
    return '';
};

/**
 * Each activity's plan appears under its own row the moment it is ready, so
 * the review is already half read by the time the whole plan lands.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string}
 */
const handlePlanProgressDone = (data, progress) => {
    markRow(data.cmid, 'done');
    progress.done += 1;
    finishChecklistRow(data.plan || {});
    renderActivityPlan(data.plan || {});
    return '';
};

/**
 * @returns {string}
 */
const handleReviewNeeded = () => 'review';

/**
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string}
 */
const handleActivityProgressInit = (data, progress) => {
    progress.total = Math.max(0, Number(data.total) || 0);
    progress.done = 0;
    paintStage('activities');
    return '';
};

/**
 * @param {Object} data
 * @returns {string}
 */
const handleActivityProgressStart = (data) => {
    markRow(data.cmid, 'running');
    return '';
};

/**
 * A failed activity is still counted and still stops looking "in progress":
 * the run itself then fails, which is what the professor is told about.
 * Shared by both the done and failed activity events.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string}
 */
const handleActivityProgressSettled = (data, progress) => {
    markRow(data.cmid, 'done');
    progress.done += 1;
    if (progress.total > 0 && progress.done >= progress.total) {
        paintStage('saving');
    }
    return '';
};

/**
 * @returns {string}
 */
const handleCompleted = () => 'completed';

/**
 * @returns {string}
 */
const handleFailed = () => 'failed';

/** Event handlers keyed by the stream event's own `type`. */
const EVENT_HANDLERS = {
    template_stage: handleTemplateStage,
    plan_progress_init: handlePlanProgressInit,
    plan_progress_start: handlePlanProgressStart,
    plan_progress_summary: handlePlanProgressSummary,
    plan_progress_part: handlePlanProgressPart,
    plan_progress_done: handlePlanProgressDone,
    review_needed: handleReviewNeeded,
    activity_progress_init: handleActivityProgressInit,
    activity_progress_start: handleActivityProgressStart,
    activity_progress_done: handleActivityProgressSettled,
    activity_progress_failed: handleActivityProgressSettled,
    completed: handleCompleted,
    failed: handleFailed,
};

/**
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string} '' to keep listening, otherwise 'completed' or 'failed'.
 */
export const applyEvent = (data, progress) => {
    const handler = EVENT_HANDLERS[data.type];
    if (!handler) {
        return '';
    }
    return handler(data, progress);
};
