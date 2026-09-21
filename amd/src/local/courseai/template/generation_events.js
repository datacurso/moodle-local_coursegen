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
 * which activity row lights up, which checklist row fills in, which phase
 * label shows. generation_watch.js decodes the stream and calls this for
 * each event; generation_stream.js owns the phase label itself and passes
 * `paintStage` in rather than this module importing it, so the two never
 * import each other.
 *
 * @module     local_coursegen/local/courseai/template/generation_events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderActivityPlan} from 'local_coursegen/local/courseai/template/plan_review';
import {
    addPlanPart,
    addPlanSummary,
    finishChecklistRow,
    openChecklist,
    startPlanEntry,
} from 'local_coursegen/local/courseai/template/thread_checklist';

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
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @param {Function} paintStage Shows one phase label, by key.
 * @returns {string} '' to keep listening, otherwise 'review'/'completed'/'failed'.
 */
export const applyEvent = (data, progress, paintStage) => {
    if (data.type === 'template_stage') {
        paintStage(data.stage);
        return '';
    }
    if (data.type === 'plan_progress_init') {
        progress.total = Math.max(0, Number(data.total) || 0);
        progress.done = 0;
        paintStage('planning');
        openChecklist(data.sections);
        return '';
    }
    if (data.type === 'plan_progress_start') {
        markRow(data.cmid, 'running');
        startPlanEntry(data);
        return '';
    }
    if (data.type === 'plan_progress_summary') {
        addPlanSummary(data);
        return '';
    }
    if (data.type === 'plan_progress_part') {
        addPlanPart(data);
        return '';
    }
    if (data.type === 'plan_progress_done') {
        markRow(data.cmid, 'done');
        progress.done += 1;
        finishChecklistRow(data.plan || {});
        // Each activity's plan appears under its own row the moment it is
        // ready, so the review is already half read by the time the whole
        // plan lands.
        renderActivityPlan(data.plan || {});
        return '';
    }
    if (data.type === 'review_needed') {
        return 'review';
    }
    if (data.type === 'activity_progress_init') {
        progress.total = Math.max(0, Number(data.total) || 0);
        progress.done = 0;
        paintStage('activities');
        return '';
    }
    if (data.type === 'activity_progress_start') {
        markRow(data.cmid, 'running');
        return '';
    }
    if (data.type === 'activity_progress_done' || data.type === 'activity_progress_failed') {
        // A failed activity is still counted and still stops looking
        // "in progress": the run itself then fails, which is what the
        // professor is told about.
        markRow(data.cmid, 'done');
        progress.done += 1;
        if (progress.total > 0 && progress.done >= progress.total) {
            paintStage('saving');
        }
        return '';
    }
    if (data.type === 'completed') {
        return 'completed';
    }
    if (data.type === 'failed') {
        return 'failed';
    }
    return '';
};
