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
 * background job - it is the job.
 *
 * It renders nothing. Free mode's generation view works by putting the page in
 * `body.cg-generating` and stamping one of three status classes on each
 * activity row that already exists; every visual state (dimmed and desaturated
 * while pending, a spinner on the icon while running, a check badge when done,
 * edit controls hidden throughout) comes from the shared stylesheet. Doing the
 * same here is what keeps the two modes looking like one product instead of
 * two: there is no second design to keep in sync, because there is no second
 * design.
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
import {createLog} from 'local_coursegen/local/courseai/ui/log';
import {
    hideWorkingIndicator,
    showWorkingIndicator,
} from 'local_coursegen/local/courseai/ui/feedback-progress';

/** The status classes the shared generation stylesheet reacts to. */
const STATUS_CLASS = {
    pending: 'cg-gen-pending',
    running: 'cg-gen-active',
    done: 'cg-gen-done',
};
const ALL_STATUS_CLASSES = Object.values(STATUS_CLASS);

/** Phase keys the service reports, plus the two this module owns. */
const STAGE_STRINGS = {
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
 * The batched string request for every phase key.
 *
 * @param {Array<string>} keys
 * @returns {Array<Object>}
 */
const stageStringRequests = (keys) => keys.map((key) => ({key: STAGE_STRINGS[key], component: 'local_coursegen'}));

/**
 * Copy the fetched phase strings onto the labels map, keyed by phase.
 *
 * @param {Object} target
 * @param {Array<string>} keys
 * @param {Array<string>} values
 */
const assignStageLabels = (target, keys, values) => {
    keys.forEach((key, index) => {
        target[key] = values[index];
    });
};

/**
 * The localised header strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by phase key, plus `title`.
 */
const getLabels = async() => {
    if (!labels) {
        const keys = Object.keys(STAGE_STRINGS);
        const requests = stageStringRequests(keys);
        const values = await getStrings([...requests, {key: TITLE_STRING, component: 'local_coursegen'}]);
        labels = {title: values[keys.length]};
        assignStageLabels(labels, keys, values);
    }
    return labels;
};

/** The left panel's thread feed, built on the shared log module. */
let log = null;
const getLog = () => {
    if (!log) {
        log = createLog({container: document.getElementById('cgLog')});
    }
    return log;
};

/** Every activity row the AI is going to generate. */
const generatedRows = () => document.querySelectorAll('[data-generation-cmid]');

/**
 * Mark one activity row with the state its generation is in.
 *
 * @param {number|string} cmid
 * @param {string} status A key of STATUS_CLASS.
 */
const markRow = (cmid, status) => {
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
const paintStage = async(key) => {
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
 * Put the page in its generating state: header visible and spinning, every
 * activity the AI will generate dimmed and waiting, edit controls gone.
 *
 * @param {string} prompt The professor's own instruction, restated as a turn.
 */
const openView = async(prompt) => {
    document.body.classList.add('cg-generating');

    // The composer goes away for the duration, the way free mode's does: there
    // is nothing left to type, and leaving an active-looking input under a run
    // that ignores it invites a second one. Its instruction is not lost - it is
    // restated in the feed as the turn that opened this generation.
    const composer = document.getElementById('tplInputBar');
    if (composer) {
        composer.hidden = true;
    }
    if (String(prompt || '').trim()) {
        getLog().add({actor: 'user', kind: 'user', message: String(prompt).trim()});
    }
    generatedRows().forEach((row) => {
        row.classList.remove(...ALL_STATUS_CLASSES);
        row.classList.add(STATUS_CLASS.pending);
    });

    const header = document.getElementById('tplGenHeader');
    const spinner = document.getElementById('tplGenSpinnerIcon');
    const check = document.getElementById('tplGenCheckIcon');
    const title = document.getElementById('tplGenTitle');
    if (header) {
        header.hidden = false;
        header.classList.remove('prv-header--done');
    }
    if (spinner) {
        spinner.style.display = '';
    }
    if (check) {
        check.style.display = 'none';
    }
    if (title) {
        title.textContent = (await getLabels()).title;
    }
};

/**
 * Take the page out of its generating state, after a failure.
 *
 * @param {string} message What went wrong, reported as a turn in the feed.
 */
const closeView = (message) => {
    document.body.classList.remove('cg-generating');
    generatedRows().forEach((row) => row.classList.remove(...ALL_STATUS_CLASSES));
    hideWorkingIndicator();
    const header = document.getElementById('tplGenHeader');
    if (header) {
        header.hidden = true;
    }
    const composer = document.getElementById('tplInputBar');
    if (composer) {
        composer.hidden = false;
    }
    if (message) {
        getLog().add({actor: 'ai', kind: 'danger', message});
    }
};

/**
 * Swap the header's spinner for its check.
 *
 * Free mode does this only on the terminal event, never when the last activity
 * lands: phases still run after that, and a check while work continues reads
 * as "finished" to someone who is waiting.
 */
const markHeaderDone = () => {
    const header = document.getElementById('tplGenHeader');
    const spinner = document.getElementById('tplGenSpinnerIcon');
    const check = document.getElementById('tplGenCheckIcon');
    if (header) {
        header.classList.add('prv-header--done');
    }
    if (spinner) {
        spinner.style.display = 'none';
    }
    if (check) {
        check.style.display = '';
    }
};

/**
 * Apply one decoded stream event.
 *
 * @param {Object} data
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {string} '' to keep listening, otherwise 'completed' or 'failed'.
 */
const applyEvent = (data, progress) => {
    switch (data.type) {
        case 'template_stage':
            paintStage(data.stage);
            return '';
        case 'activity_progress_init':
            progress.total = Math.max(0, Number(data.total) || 0);
            progress.done = 0;
            paintStage('activities');
            return '';
        case 'activity_progress_start':
            markRow(data.cmid, 'running');
            return '';
        case 'activity_progress_done':
        case 'activity_progress_failed':
            // A failed activity is still counted and still stops looking
            // "in progress": the run itself then fails, which is what the
            // professor is told about.
            markRow(data.cmid, 'done');
            progress.done += 1;
            if (progress.total > 0 && progress.done >= progress.total) {
                paintStage('saving');
            }
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
 * @param {string} prompt The professor's instruction, restated in the feed.
 * @returns {Promise<Object>} The built course, as buildCourse resolved it.
 */
export const runGenerationStream = async(streamUrl, buildCourse, prompt) => {
    await openView(prompt);
    await paintStage('connecting');

    return new Promise((resolve, reject) => {
        const progress = {total: 0, done: 0};
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

        const fail = (message) => finish(() => {
            closeView(message);
            reject(new Error(message));
        });

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
                markHeaderDone();
                paintStage('building');
                finish(() => buildCourse().then(resolve).catch(reject));
            } else if (outcome === 'failed') {
                fail(data.message || 'The generation could not be completed.');
            }
        });

        source.addEventListener('done', () => {
            // A 'done' with no terminal event before it means the stream ended
            // without ever saying how: reported as a failure rather than leaving
            // the professor watching a header that will never resolve.
            fail('The generation ended unexpectedly.');
        });

        source.onerror = () => {
            // EventSource reconnects by itself on a transient drop, reporting
            // CONNECTING while it does; only a closed connection is a failure.
            if (source.readyState === EventSource.CONNECTING) {
                return;
            }
            fail('The connection to the generation was lost.');
        };
    });
};
