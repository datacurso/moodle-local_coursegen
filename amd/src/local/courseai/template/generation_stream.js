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
 * The run generates at once and then stops so the professor can read the
 * result and approve it; asking for changes generates the activities named
 * again and stops again, which is why runGenerationStream loops. Watching one
 * pass of the SSE connection lives in generation_watch.js; turning a decoded
 * event into a DOM update lives in generation_events.js. This module owns the
 * phase label and the generating/not-generating view state, and passes both
 * of the others what they need as callbacks rather than importing them
 * circularly.
 *
 * It renders nothing beyond that. Free mode's generation view works by
 * putting the page in `body.cg-generating` and stamping one of three status
 * classes on each activity row that already exists; every visual state comes
 * from the shared stylesheet. Doing the same here is what keeps the two modes
 * looking like one product instead of two.
 *
 * @module     local_coursegen/local/courseai/template/generation_stream
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {askQuestion, askRetry} from 'local_coursegen/local/courseai/template/generation_question';
import {
    announceTemplate,
    milestone,
    resetThread,
    restorePicker,
    turn,
} from 'local_coursegen/local/courseai/template/thread';
import {refreshPreviewLinks} from 'local_coursegen/local/courseai/template/preview';
import {hideWorkingIndicator, showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {
    ALL_STATUS_CLASSES,
    STATUS_CLASS,
    applyEvent,
    resetSeen,
} from 'local_coursegen/local/courseai/template/generation_events';
import {watchOnce} from 'local_coursegen/local/courseai/template/generation_watch';
import {hideHeader, markHeaderDone, showGeneratingHeader} from 'local_coursegen/local/courseai/template/generation_header';

/** Phase keys the service reports, plus the two this module owns. */
const STAGE_STRINGS = {
    reviewing: 'courseai_template_stage_reviewing',
    style: 'courseai_template_stage_style',
    activities: 'courseai_template_stage_activities',
    activity_images: 'courseai_template_stage_activity_images',
    section_images: 'courseai_template_stage_section_images',
    saving: 'courseai_template_stage_saving',
    connecting: 'courseai_template_stage_connecting',
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

/** Every activity row the AI is going to generate. */
const generatedRows = () => document.querySelectorAll('[data-generation-uid]');

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
    // Waiting for the professor is not work in progress: free mode shows no
    // indicator then, only the decision card.
    if (key === 'reviewing') {
        hideWorkingIndicator();
        return;
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
 * @param {Object} context {prompt, templateName} for the opening turns.
 */
const openView = async(context) => {
    document.body.classList.add('cg-generating');
    resetSeen();

    // The composer goes away for the duration, the way free mode's does: there
    // is nothing left to type, and leaving an active-looking input under a run
    // that ignores it invites a second one. Its instruction is not lost - it is
    // restated in the feed as the turn that opened this generation.
    const composer = document.getElementById('tplInputBar');
    if (composer) {
        composer.hidden = true;
    }
    resetThread();
    await announceTemplate(context.templateName);
    turn('user', 'user', String(context.prompt || '').trim());
    milestone('courseai_template_log_starting');
    generatedRows().forEach((row) => {
        row.classList.remove(...ALL_STATUS_CLASSES);
        row.classList.add(STATUS_CLASS.pending);
    });
    showGeneratingHeader((await getLabels()).title);
    refreshPreviewLinks();
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
    hideHeader();
    const composer = document.getElementById('tplInputBar');
    if (composer) {
        composer.hidden = false;
    }
    restorePicker();
    if (message) {
        turn('ai', 'danger', message);
    }
};

/**
 * The run completed: close the header and build the course.
 *
 * @param {Function} buildCourse Called once the run completes; resolves to {courseurl}.
 * @returns {Promise<*>} What buildCourse resolved to.
 */
const finishRun = (buildCourse) => {
    markHeaderDone();
    hideWorkingIndicator();
    milestone('courseai_template_log_completed');
    return buildCourse();
};

/**
 * The run paused on a question: show it and wait for the answer to be stored.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @param {Object} question The question event.
 */
const waitForAnswer = async(sessionId, question) => {
    hideWorkingIndicator();
    milestone('template_agent_log_waiting');
    await askQuestion(sessionId, question);
    milestone('template_agent_log_resumed');
    await paintStage('activities');
};

/**
 * The run stopped for a reason a new attempt may fix: show it and wait for the teacher to try again.
 *
 * @param {Object} failure The failed event.
 */
const waitForRetry = async(failure) => {
    hideWorkingIndicator();
    await askRetry(failure.message);
    await paintStage('connecting');
};

/**
 * Follow the stream of a run, pass by pass, until the run completes.
 *
 * @param {string} streamUrl Relay URL of the stream of the run.
 * @param {Function} buildCourse Creates the course once the run completed.
 * @param {number} sessionId Local session id, for example 139.
 * @param {{total: number, done: number}} progress Counters of the activities written.
 * @returns {Promise<*>} What buildCourse returns.
 */
const followRun = async(streamUrl, buildCourse, sessionId, progress) => {
    for (;;) {
        const {outcome, data} = await watchOnce(
            streamUrl,
            progress,
            (eventData, eventProgress) => applyEvent(eventData, eventProgress, paintStage),
            closeView
        );
        if (outcome === 'completed') {
            return finishRun(buildCourse);
        }
        if (outcome === 'retry') {
            await waitForRetry(data);
        } else {
            await waitForAnswer(sessionId, data);
        }
    }
};

/**
 * Run a template generation: open the stream, show what the AI does, stop to ask what it needs and go on.
 *
 * @param {string} streamUrl Relay URL of the stream of the run.
 * @param {Function} buildCourse Creates the course once the run completed.
 * @param {number} sessionId Local session id, for example 139.
 * @param {{prompt: string, templateName: string}} context What the teacher asked and the template used.
 * @returns {Promise<*>} What buildCourse returns.
 */
export const runGenerationStream = async(streamUrl, buildCourse, sessionId, context) => {
    await openView(context);
    await paintStage('connecting');
    return followRun(streamUrl, buildCourse, sessionId, {total: 0, done: 0});
};

const lastFailure = (events) => {
    const failures = events.filter((event) => event && event.type === 'failed');
    return failures[failures.length - 1] || null;
};

const replayEvents = (events, progress) => {
    for (const event of events) {
        applyEvent(event, progress, paintStage);
    }
};

const parseJson = (text, fallback) => {
    try {
        return JSON.parse(text);
    } catch (exception) {
        return fallback;
    }
};

/**
 * Repaint a template generation after a reload and go on from where it was: replay what was shown, show the
 * pending question again, or follow the stream.
 *
 * @param {Object} snapshot The state of the run: status, streamurl, pendingquestion and progressevents.
 * @param {Function} buildCourse Creates the course once the run completed.
 * @param {number} sessionId Local session id, for example 139.
 * @param {{prompt: string, templateName: string}} context What the teacher asked and the template used.
 * @returns {Promise<*>} What buildCourse returns, or null when the run cannot go on.
 */
export const resumeGenerationStream = async(snapshot, buildCourse, sessionId, context) => {
    await openView(context);
    const progress = {total: 0, done: 0};
    const events = parseJson(snapshot.progressevents, []);
    replayEvents(events, progress);

    if (snapshot.status === 'COMPLETED') {
        return finishRun(buildCourse);
    }
    if (snapshot.status === 'FAILED') {
        const failure = lastFailure(events);
        if (failure === null || failure.retryable !== true) {
            let message = '';
            if (failure !== null) {
                message = failure.message;
            }
            closeView(message);
            return null;
        }
        await waitForRetry(failure);
    }
    if (snapshot.status === 'WAITING_USER') {
        const question = parseJson(snapshot.pendingquestion, null);
        if (question !== null) {
            await waitForAnswer(sessionId, question);
        }
    }
    await paintStage('connecting');
    return followRun(snapshot.streamurl, buildCourse, sessionId, progress);
};
