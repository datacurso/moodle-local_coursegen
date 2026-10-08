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

import {askQuestion, askRetry} from 'local_coursegen/local/courseai/template/generation_question';
import {
    announceTemplate,
    milestone,
    resetThread,
    restorePicker,
    turn,
} from 'local_coursegen/local/courseai/template/thread';
import {refreshPreviewLinks} from 'local_coursegen/local/courseai/template/preview';
import {hideWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {
    ALL_STATUS_CLASSES,
    STATUS_CLASS,
    applyEvent,
    resetSeen,
} from 'local_coursegen/local/courseai/template/generation_events';
import {watchOnce} from 'local_coursegen/local/courseai/template/generation_watch';
import {hideHeader, markHeaderDone, showGeneratingHeader} from 'local_coursegen/local/courseai/template/generation_header';
import {ReviewFlow} from 'local_coursegen/local/courseai/template/review_flow';
import {getLabels, paintStage} from 'local_coursegen/local/courseai/template/generation_stage';
import {reviewResult} from 'local_coursegen/local/courseai/template/generation_adjust';
import {failureWords, lastCompleted, lastFailure, parseJson} from 'local_coursegen/local/courseai/template/run_events';

/** Every activity row the AI is going to generate. */
const generatedRows = () => document.querySelectorAll('[data-generation-uid]');

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
 * Show the review of what the run generated: the header rests, the working indicator goes away and the
 * feed says the course is ready to be read.
 *
 * @returns {Promise<void>}
 */
const paintReview = async() => {
    markHeaderDone();
    hideWorkingIndicator();
    await paintStage('reviewing');
    milestone('courseai_template_log_review_ready');
};

/**
 * The teacher accepted the generated course: go on to the review of its name and build it.
 *
 * @param {Function} buildCourse Creates the course once the teacher accepted the result.
 * @returns {Promise<*>} What buildCourse resolved to.
 */
const finishRun = (buildCourse) => {
    milestone('courseai_template_log_approved', 'user', 'success');
    return buildCourse();
};

/**
 * The run paused on a question: show it and wait for the answer to be stored.
 *
 * @param {number} sessionId Local session id, for example 139.
 * @param {Object} question The question event.
 * @param {ReviewFlow} flow
 */
const waitForAnswer = async(sessionId, question, flow) => {
    hideWorkingIndicator();
    milestone('template_agent_log_waiting');
    await askQuestion(sessionId, question);
    flow.answered();
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
    const words = await failureWords(failure);
    await askRetry(words);
    await paintStage('connecting');
};

/**
 * The run completed: review what it generated and, when the teacher accepts it, build the course.
 *
 * @param {Object} data The completed event.
 * @param {Object} run {buildCourse, sessionId, flow} of the run.
 * @returns {Promise<{done: boolean, value: *}>} done is true once the course was built.
 */
const completePass = async(data, run) => {
    milestone('courseai_template_log_completed');
    await paintReview();
    const shown = run.flow.completed(data);
    const verdict = await reviewResult(run.flow, shown, run.sessionId);
    if (verdict !== 'accepted') {
        return {done: false, value: null};
    }
    const course = await finishRun(run.buildCourse);
    return {done: true, value: course};
};

/**
 * Act on how one pass of the stream ended.
 *
 * @param {string} outcome 'completed', 'retry' or 'question'.
 * @param {Object} data The event the pass ended on.
 * @param {Object} run {buildCourse, sessionId, flow} of the run.
 * @returns {Promise<{done: boolean, value: *}>}
 */
const settlePass = async(outcome, data, run) => {
    if (outcome === 'completed') {
        return completePass(data, run);
    }
    if (outcome === 'retry') {
        await waitForRetry(data);
        return {done: false, value: null};
    }
    await waitForAnswer(run.sessionId, data, run.flow);
    return {done: false, value: null};
};

/**
 * Follow the stream of a run, pass by pass, until the teacher accepts a completed run and the course is built.
 *
 * @param {string} streamUrl Relay URL of the stream of the run.
 * @param {Object} run {buildCourse, sessionId, flow} of the run.
 * @param {{total: number, done: number}} progress Counters of the activities written.
 * @returns {Promise<*>} What buildCourse returns.
 */
const followRun = async(streamUrl, run, progress) => {
    for (;;) {
        const {outcome, data} = await watchOnce(
            streamUrl,
            progress,
            (eventData, eventProgress) => applyEvent(eventData, eventProgress, paintStage),
            closeView
        );
        const settled = await settlePass(outcome, data, run);
        if (settled.done) {
            return settled.value;
        }
    }
};

/**
 * Run a template generation: open the stream, show what the AI does, stop to ask what it needs, show the review
 * when it completes and go on when the teacher asks for changes.
 *
 * @param {string} streamUrl Relay URL of the stream of the run.
 * @param {Function} buildCourse Creates the course once the teacher accepted the result.
 * @param {number} sessionId Local session id, for example 139.
 * @param {{prompt: string, templateName: string}} context What the teacher asked and the template used.
 * @returns {Promise<*>} What buildCourse returns.
 */
export const runGenerationStream = async(streamUrl, buildCourse, sessionId, context) => {
    await openView(context);
    await paintStage('connecting');
    const run = {buildCourse, sessionId, flow: new ReviewFlow()};
    return followRun(streamUrl, run, {total: 0, done: 0});
};

const replayEvents = (events, progress) => {
    for (const event of events) {
        applyEvent(event, progress, paintStage);
    }
};

/**
 * A page that reloads with a completed run: show its review again and, once the teacher accepts, build the course.
 *
 * @param {Array<Object>} events The events of the run, in order.
 * @param {Object} run {buildCourse, sessionId, flow} of the run.
 * @returns {Promise<*>} What buildCourse returns, or null when a change request goes on.
 */
const resumeReview = async(events, run) => {
    await paintReview();
    const shown = run.flow.completed(lastCompleted(events));
    const verdict = await reviewResult(run.flow, shown, run.sessionId);
    if (verdict === 'accepted') {
        return finishRun(run.buildCourse);
    }
    return null;
};

/**
 * Repaint a template generation after a reload and go on from where it was: replay what was shown, show the
 * review or the pending question again, or follow the stream.
 *
 * @param {Object} snapshot The state of the run: status, streamurl, pendingquestion and progressevents.
 * @param {Function} buildCourse Creates the course once the teacher accepted the result.
 * @param {number} sessionId Local session id, for example 139.
 * @param {{prompt: string, templateName: string}} context What the teacher asked and the template used.
 * @returns {Promise<*>} What buildCourse returns, or null when the run cannot go on.
 */
export const resumeGenerationStream = async(snapshot, buildCourse, sessionId, context) => {
    await openView(context);
    const progress = {total: 0, done: 0};
    const events = parseJson(snapshot.progressevents, []);
    replayEvents(events, progress);

    const run = {buildCourse, sessionId, flow: new ReviewFlow()};
    const screen = run.flow.restore(snapshot, events);

    if (screen.screen === 'review') {
        const course = await resumeReview(events, run);
        if (course !== null) {
            return course;
        }
    }
    if (screen.screen === 'failed') {
        const failure = lastFailure(events);
        const message = await failureWords(failure);
        closeView(message);
        return null;
    }
    if (screen.screen === 'retry') {
        await waitForRetry(lastFailure(events));
    }
    if (screen.screen === 'question') {
        const question = parseJson(snapshot.pendingquestion, null);
        if (question !== null) {
            await waitForAnswer(sessionId, question, run.flow);
        }
    }
    await paintStage('connecting');
    return followRun(snapshot.streamurl, run, progress);
};
