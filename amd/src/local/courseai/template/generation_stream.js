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
 * Live view of one course-from-template generation.
 *
 * Opening this stream is what RUNS the generation, exactly as free-mode course
 * creation and single-activity generation already work: the service seeds the
 * session on /init and only advances the graph while something is consuming
 * its SSE endpoint. So this module is not a progress decoration on top of a
 * background job — it is the job.
 *
 * Progress is reported per activity and keyed by the activity's own id, never
 * by arrival order: activities are generated concurrently, so the order they
 * finish in says nothing about which one just landed.
 *
 * @module     local_coursegen/local/courseai/template/generation_stream
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

/** Phase keys the service reports, in the order the graph runs them. */
const STAGE_KEYS = ['style', 'activities', 'activity_images', 'section_images', 'saving'];

/** Lang string per phase key, plus the two the client owns. */
const STAGE_STRINGS = {
    style: 'courseai_template_stage_style',
    activities: 'courseai_template_stage_activities',
    activity_images: 'courseai_template_stage_activity_images',
    section_images: 'courseai_template_stage_section_images',
    saving: 'courseai_template_stage_saving',
    connecting: 'courseai_template_stage_connecting',
    building: 'courseai_template_stage_building',
};

let stageLabels = null;

/**
 * The localised phase labels, fetched once.
 *
 * @returns {Promise<Object>} Keyed by phase key.
 */
const getStageLabels = async() => {
    if (!stageLabels) {
        const keys = Object.keys(STAGE_STRINGS);
        const values = await getStrings(
            keys.map((key) => ({key: STAGE_STRINGS[key], component: 'local_coursegen'}))
        );
        stageLabels = {};
        keys.forEach((key, index) => {
            stageLabels[key] = values[index];
        });
    }
    return stageLabels;
};

/**
 * The activity row that answers to one generation id.
 *
 * @param {number|string} cmid
 * @returns {HTMLElement|null}
 */
const rowFor = (cmid) => document.querySelector(`[data-generation-cmid="${cmid}"]`);

/**
 * Mark one activity row with the state its generation is in.
 *
 * @param {number|string} cmid
 * @param {string} status One of 'running', 'done', 'failed'.
 */
const markRow = (cmid, status) => {
    const row = rowFor(cmid);
    if (!row) {
        return;
    }
    row.classList.remove('cg-tpl-gen-running', 'cg-tpl-gen-finished', 'cg-tpl-gen-error');
    if (status === 'running') {
        row.classList.add('cg-tpl-gen-running');
    } else if (status === 'done') {
        row.classList.add('cg-tpl-gen-finished');
    } else {
        row.classList.add('cg-tpl-gen-error');
    }
};

/**
 * The progress panel's elements, or null when the page does not have it.
 *
 * @returns {Object|null}
 */
const panel = () => {
    const wrap = document.getElementById('tplGenProgress');
    if (!wrap) {
        return null;
    }
    return {
        wrap,
        stage: document.getElementById('tplGenStage'),
        count: document.getElementById('tplGenCount'),
        track: document.getElementById('tplGenTrack'),
        fill: document.getElementById('tplGenFill'),
    };
};

/**
 * Per-run mutable view state.
 *
 * @returns {Object}
 */
const newProgress = () => ({total: 0, done: 0});

/**
 * Repaint the bar and the counter from the current totals.
 *
 * @param {Object} progress
 */
const paintProgress = (progress) => {
    const ui = panel();
    if (!ui) {
        return;
    }
    // Before the total is known the bar stays empty rather than guessing a
    // percentage: an invented value that later jumps backwards reads as a bug.
    const percent = progress.total > 0
        ? Math.round((progress.done / progress.total) * 100)
        : 0;
    if (ui.fill) {
        ui.fill.style.width = `${percent}%`;
    }
    if (ui.track) {
        ui.track.setAttribute('aria-valuenow', String(percent));
    }
    if (ui.count) {
        ui.count.textContent = progress.total > 0 ? `${progress.done}/${progress.total}` : '';
    }
};

/**
 * Show one phase label.
 *
 * @param {string} key
 */
const paintStage = async(key) => {
    const ui = panel();
    if (!ui || !ui.stage) {
        return;
    }
    const labels = await getStageLabels();
    ui.stage.textContent = labels[key] || '';
};

/**
 * Reveal the progress panel and reset it for a fresh run.
 */
const openPanel = () => {
    const ui = panel();
    if (!ui) {
        return;
    }
    ui.wrap.hidden = false;
    if (ui.fill) {
        ui.fill.style.width = '0%';
    }
    if (ui.count) {
        ui.count.textContent = '';
    }
    document.querySelectorAll('[data-generation-cmid]').forEach((row) => {
        row.classList.remove('cg-tpl-gen-running', 'cg-tpl-gen-finished', 'cg-tpl-gen-error');
    });
};

/**
 * Hide the progress panel, on failure or when leaving the page.
 */
const closePanel = () => {
    const ui = panel();
    if (ui) {
        ui.wrap.hidden = true;
    }
};

/**
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress
 * @returns {string} '' to keep listening, otherwise 'completed' or 'failed'.
 */
const applyEvent = (data, progress) => {
    switch (data.type) {
        case 'template_stage':
            if (STAGE_KEYS.indexOf(data.stage) !== -1) {
                paintStage(data.stage);
            }
            return '';
        case 'activity_progress_init':
            progress.total = Math.max(0, Number(data.total) || 0);
            progress.done = 0;
            paintStage('activities');
            paintProgress(progress);
            return '';
        case 'activity_progress_start':
            markRow(data.cmid, 'running');
            return '';
        case 'activity_progress_done':
        case 'activity_progress_failed':
            markRow(data.cmid, data.type === 'activity_progress_done' ? 'done' : 'failed');
            progress.done += 1;
            paintProgress(progress);
            return '';
        case 'completed':
            return 'completed';
        case 'failed':
            return 'failed';
        default:
            return '';
    }
};

/**
 * Run one generation and resolve when its course has been built.
 *
 * @param {string} streamUrl SSE endpoint returned by start_template_generation.
 * @param {Function} buildCourse Called once the run completes; resolves to {courseurl}.
 * @returns {Promise<Object>} The built course, as buildCourse resolved it.
 */
export const runGenerationStream = (streamUrl, buildCourse) => new Promise((resolve, reject) => {
    const progress = newProgress();
    openPanel();
    paintStage('connecting');
    paintProgress(progress);

    const source = new EventSource(streamUrl);
    let settled = false;

    const finish = (action) => {
        if (settled) {
            return;
        }
        settled = true;
        source.close();
        action();
    };

    source.addEventListener('message', (event) => {
        let data = null;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        const outcome = applyEvent(data, progress);
        if (outcome === 'completed') {
            // The result payload stays server-side: the browser only reports
            // that the run finished, and Moodle fetches it to build the course.
            paintStage('building');
            finish(() => buildCourse().then(resolve).catch(reject));
        } else if (outcome === 'failed') {
            finish(() => {
                closePanel();
                reject(new Error(data.message || 'The generation could not be completed.'));
            });
        }
    });

    source.addEventListener('done', () => {
        // A 'done' with no terminal event before it means the stream ended
        // without ever saying how: treated as a failure rather than leaving
        // the professor watching a bar that will never move again.
        finish(() => {
            closePanel();
            reject(new Error('The generation ended unexpectedly.'));
        });
    });

    source.onerror = () => {
        // EventSource reconnects by itself on a transient drop, reporting
        // CONNECTING while it does; only a closed connection is a real failure.
        if (source.readyState === EventSource.CONNECTING) {
            return;
        }
        finish(() => {
            closePanel();
            reject(new Error('The connection to the generation was lost.'));
        });
    };
});
