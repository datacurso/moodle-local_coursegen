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
import {askForDecision} from 'local_coursegen/local/courseai/template/generation_review';
import {sendTemplateReviewFeedback} from 'local_coursegen/local/courseai/template/repository';
import {ReviewFlow, adjustable, errorMessage} from 'local_coursegen/local/courseai/template/review_flow';

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
const ADJUST_FAILED_STRING = 'courseai_template_adjust_failed';
const ADJUST_TOO_LONG_STRING = 'courseai_template_adjust_toolong';

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
        const values = await getStrings([
            ...requests,
            {key: TITLE_STRING, component: 'local_coursegen'},
            {key: ADJUST_FAILED_STRING, component: 'local_coursegen'},
            {key: ADJUST_TOO_LONG_STRING, component: 'local_coursegen'},
        ]);
        labels = {
            title: values[keys.length],
            adjustFailed: values[keys.length + 1],
            adjustTooLong: values[keys.length + 2],
        };
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
 * Send a change request to the run and tell the flow how it went.
 *
 * @param {ReviewFlow} flow
 * @param {{callId: string, instruction: string, aid: string}} sent What the flow validated.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<boolean>} True when the service stored the request.
 */
const sendAdjustment = async(flow, sent, sessionId) => {
    try {
        await sendTemplateReviewFeedback(sessionId, sent.callId, sent.instruction, sent.aid);
    } catch (error) {
        const texts = await getLabels();
        flow.feedbackFailed(error);
        turn('ai', 'danger', errorMessage(error, texts.adjustFailed));
        return false;
    }
    flow.feedbackStored();
    return true;
};

/**
 * The run goes on from its draft after a change request: say so and show it working again.
 *
 * @param {{instruction: string}} sent What was sent.
 * @returns {Promise<void>}
 */
const startAdjustedRound = async(sent) => {
    turn('user', 'user', sent.instruction);
    milestone('courseai_template_log_adjusting');
    resetSeen();
    showGeneratingHeader((await getLabels()).title);
    await paintStage('activities');
};

/**
 * Settle one decision of the teacher.
 *
 * @param {ReviewFlow} flow
 * @param {Object} outcome What the flow answered to the decision.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<string>} 'accepted', 'adjusting' or 'again' when the review stays open.
 */
const settleDecision = async(flow, outcome, sessionId) => {
    if (outcome.screen === 'accepted') {
        return 'accepted';
    }
    if (outcome.error === 'toolong') {
        const texts = await getLabels();
        turn('ai', 'danger', texts.adjustTooLong);
        return 'again';
    }
    if (!outcome.send) {
        return 'again';
    }
    const stored = await sendAdjustment(flow, outcome.send, sessionId);
    if (!stored) {
        return 'again';
    }
    await startAdjustedRound(outcome.send);
    return 'adjusting';
};

/**
 * Keep the review open until the teacher accepts the course or a change request is stored.
 *
 * @param {ReviewFlow} flow
 * @param {{generated: Array<Object>}} shown The review that is on screen.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<string>} 'accepted' or 'adjusting'.
 */
const reviewResult = async(flow, shown, sessionId) => {
    const rows = adjustable(shown.generated);
    for (;;) {
        const decision = await askForDecision(rows);
        const outcome = flow.submit(decision);
        const verdict = await settleDecision(flow, outcome, sessionId);
        if (verdict !== 'again') {
            return verdict;
        }
    }
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
    await askRetry(failure.message);
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

const lastFailure = (events) => {
    const failures = events.filter((event) => event && event.type === 'failed');
    return failures[failures.length - 1] || null;
};

const lastCompleted = (events) => {
    const completed = events.filter((event) => event && event.type === 'completed');
    return completed[completed.length - 1] || null;
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
        let message = '';
        if (failure !== null) {
            message = failure.message;
        }
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
