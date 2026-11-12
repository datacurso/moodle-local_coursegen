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
import {askForDecision} from 'local_coursegen/local/courseai/template/generation_review';
import {
    announceTemplate,
    milestone,
    resetThread,
    restorePicker,
    showImagesInSummary,
    turn,
} from 'local_coursegen/local/courseai/template/thread';
import {refreshPreviewLinks} from 'local_coursegen/local/courseai/template/preview';
import {
    answerTemplateQuestions,
    retryTemplateGeneration,
    sendTemplateReviewFeedback,
    stopTemplateGeneration,
} from 'local_coursegen/local/courseai/template/repository';
import {askQuestions, getQuestionStrings, summarize} from 'local_coursegen/local/courseai/template/generation_questions';
import {pickFile} from 'local_coursegen/local/courseai/template/syllabus_picker';
import {
    STATE,
    bindControlButtons,
    createRunControl,
    renderControl,
} from 'local_coursegen/local/courseai/template/generation_control';
import {askToRetry} from 'local_coursegen/local/courseai/template/generation_retry';
import {retriesOver, retriesUsedByService, waitBeforeRetry} from 'local_coursegen/local/courseai/template/auto_retry';
import {ATTEMPTS, delayFor, isRetryable} from 'local_coursegen/local/courseai/template/retry_schedule';
import {resetStatus} from 'local_coursegen/local/courseai/template/generation_status';
import {applySessionName} from 'local_coursegen/local/courseai/template/session_name';
import {hideWorkingIndicator, showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {ALL_STATUS_CLASSES, STATUS_CLASS, applyEvent} from 'local_coursegen/local/courseai/template/generation_events';
import {watchOnce} from 'local_coursegen/local/courseai/template/generation_watch';
import {hideHeader, markHeaderDone, showGeneratingHeader} from 'local_coursegen/local/courseai/template/generation_header';

/** The person closed the generation after an error: nothing else is to be reported about it. */
export class GenerationStopped extends Error {}

/** Phase keys the service reports, plus the two this module owns. */
// Body classes the styles of the left column read: waiting for the professor, and the run is over.
const PAUSED_CLASS = 'cg-generation-paused';
const SETTLED_CLASS = 'cg-generation-settled';

const STAGE_STRINGS = {
    reviewing: 'courseai_template_stage_reviewing',
    style: 'courseai_template_stage_style',
    activities: 'courseai_template_stage_activities',
    activity_images: 'courseai_template_stage_activity_images',
    section_images: 'courseai_template_stage_section_images',
    saving: 'courseai_template_stage_saving',
    connecting: 'courseai_template_stage_connecting',
    stopping: 'courseai_template_stage_stopping',
    paused: 'courseai_template_stage_paused',
    asking: 'courseai_template_stage_asking',
};
const UNCONFIRMED_STRING = 'courseai_template_stop_unconfirmed';
const TITLE_STRING = 'courseai_template_generating_title';

let labels = null;

// The stop and resume of the run on screen: it exists from the click on Generate, so the person can stop even while
// the material is still being sent, and it is released when the view is closed or the run ends.
let activeControl = null;
let unbindButtons = null;

/**
 * The stop and resume control of the run on screen, made on the first call.
 *
 * @returns {Object} From createRunControl.
 */
const ensureControl = () => {
    if (!activeControl) {
        activeControl = createRunControl((state) => {
            renderControl(state);
            if (state === STATE.STOPPING) {
                // The page stops looking busy at the click, before the service has even answered.
                document.body.classList.add(PAUSED_CLASS);
                paintStage('stopping');
            }
        });
        unbindButtons = bindControlButtons(activeControl);
        activeControl.begin();
    }
    return activeControl;
};

/** Let go of the control of the run: no button is offered and nothing is left bound. */
const releaseControl = () => {
    if (activeControl) {
        activeControl.end();
    }
    if (unbindButtons) {
        unbindButtons();
    }
    activeControl = null;
    unbindButtons = null;
};

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
            {key: UNCONFIRMED_STRING, component: 'local_coursegen'},
        ]);
        labels = {title: values[keys.length], unconfirmed: values[keys.length + 1]};
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
    // Waiting for the professor, or for the run to stop, is not work in progress: free mode shows no
    // indicator then, only the decision card.
    if (key === 'reviewing' || key === 'stopping' || key === 'paused' || key === 'asking') {
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
 * @param {Object} context What the run is generated with: {template, language, syllabus, instruction}.
 */
const openView = async(context) => {
    document.body.classList.add('cg-generating');
    document.body.classList.remove(PAUSED_CLASS, SETTLED_CLASS);
    ensureControl();

    // The composer goes away for the duration, the way free mode's does: there
    // is nothing left to type, and leaving an active-looking input under a run
    // that ignores it invites a second one. Its instruction is not lost - it is
    // restated in the feed as the turn that opened this generation.
    const composer = document.getElementById('tplInputBar');
    if (composer) {
        composer.hidden = true;
    }
    resetThread();
    await announceTemplate(context);
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
    releaseControl();
    document.body.classList.remove('cg-generating', PAUSED_CLASS, SETTLED_CLASS);
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
 * Open the generating page at once, before the service has answered: sending the material to the AI can take a
 * while, and the professor must not wait in front of an empty screen.
 *
 * @param {Object} context What the run is generated with: {template, language, syllabus, instruction}.
 */
export const beginGenerationView = async(context) => {
    await openView(context);
    await paintStage('connecting');
};

/**
 * Take the page out of its generating state when the run could not even start.
 *
 * @param {string} message What went wrong, reported as a turn in the feed (none when empty).
 */
export const abortGenerationView = (message) => {
    closeView(message);
};

/**
 * The run was stopped: tell the service to stop for certain, say so in the thread, and wait for Resume.
 *
 * The stream is already closed. Closing it is not enough on its own, so the service is also told, and it answers
 * once nothing of the run is going any more. If that cannot be confirmed the person is told, and Resume stays
 * available: opening the stream again makes the service end any run it still had.
 *
 * @param {number} sessionId
 * @param {Object} control The stop and resume of the run, from createRunControl.
 * @returns {Promise<void>} Resolves when the person resumed and the page is ready to open the stream again.
 */
const settleStop = async(sessionId, control) => {
    hideWorkingIndicator();
    document.body.classList.add(PAUSED_CLASS);
    await paintStage('paused');
    milestone('courseai_template_log_stopped');
    let confirmed = false;
    try {
        const answer = await stopTemplateGeneration(sessionId);
        confirmed = answer?.stopped !== false;
    } catch (error) {
        confirmed = false;
    }
    if (!confirmed) {
        turn('ai', 'danger', (await getLabels()).unconfirmed);
    }
    control.markStopped();
    await control.waitForResume();
    document.body.classList.remove(PAUSED_CLASS);
    showGeneratingHeader((await getLabels()).title);
    milestone('courseai_template_log_resuming');
    await paintStage('connecting');
    resetStatus();
};

/**
 * The run asked questions before it started: show them in the conversation, send the answers, and go on.
 *
 * The questions are asked again when the answers cannot be sent (they did not fit, or there was no connection):
 * the run never goes on with answers the service did not take.
 *
 * @param {Object} data The event of the service: {questions, can_skip}.
 * @param {number} sessionId
 * @returns {Promise<void>} Resolves once the service took the answers.
 */
const settleQuestions = async(data, sessionId) => {
    hideWorkingIndicator();
    document.body.classList.add(PAUSED_CLASS);
    await paintStage('asking');
    const text = await getQuestionStrings();
    for (;;) {
        // eslint-disable-next-line no-await-in-loop
        const reply = await askQuestions(data, {pickFile});
        try {
            // eslint-disable-next-line no-await-in-loop
            const taken = await answerTemplateQuestions(sessionId, reply.skipped, reply.answers);
            if (taken && typeof taken.images === 'boolean') {
                // eslint-disable-next-line no-await-in-loop
                await showImagesInSummary(taken.images);
            }
        } catch (error) {
            turn('ai', 'danger', error.message || text.required);
            continue;
        }
        turn('user', 'user', summarize(data.questions || [], reply, text));
        break;
    }
    document.body.classList.remove(PAUSED_CLASS);
    showGeneratingHeader((await getLabels()).title);
    await paintStage('connecting');
};

/**
 * Run one generation, pausing for the professor's review, and resolve when
 * the course has been built.
 *
 * @param {string} streamUrl SSE endpoint returned by start_template_generation.
 * @param {Function} buildCourse Called once the run completes; resolves to {courseurl}.
 * @param {number} sessionId Session the review answers belong to.
 * @param {Object} context What the run is generated with, for the opening message.
 * @param {boolean} viewOpened Whether beginGenerationView already put the page in its generating state.
 * @returns {Promise<Object>} The built course, as buildCourse resolved it.
 */
export const runGenerationStream = async(streamUrl, buildCourse, sessionId, context, viewOpened = false) => {
    if (!viewOpened) {
        await beginGenerationView(context);
    }

    const control = ensureControl();
    try {
        return await runPasses(streamUrl, buildCourse, sessionId, control);
    } finally {
        releaseControl();
    }
};

/** Whether the failures in a row that are being retried started with the AI failing and not the connection. */
let aiInSeries = false;

/**
 * Try again by itself, waiting longer each time, and say so while it does.
 *
 * The wait before each retry doubles, and the person sees which retry it is, of how many, and how long is left. The
 * count is the one of the failures in a row: it is handed in, so that a run that went on between two failures starts
 * again from the first retry.
 *
 * @param {Error} error What stopped the run.
 * @param {number} sessionId
 * @param {Function} next Counts one more failure in a row and returns the count.
 * @param {Function} count Returns the failures in a row.
 * @returns {Promise<boolean>} True when the run was opened again; false when the person has to decide.
 */
const retryByItself = async(error, sessionId, next, count) => {
    let reason = error;
    while (isRetryable(reason) && retriesUsedByService() + count() < ATTEMPTS) {
        // What the person reads is the cause the series started with: when the AI failed first, the retries that follow
        // are about the AI, even if the pass that failed again ended without saying why.
        const ownsFailure = reason.fromService === true;
        if (ownsFailure) {
            aiInSeries = true;
        } else if (count() === 0) {
            aiInSeries = false;
        }
        const attempt = next();
        // The retries of one failure are made one after the other: each one waits for the one before it.
        // eslint-disable-next-line no-await-in-loop
        await waitBeforeRetry({
            attempt,
            total: ATTEMPTS,
            seconds: delayFor(attempt),
            aiFailed: aiInSeries,
        });
        try {
            // eslint-disable-next-line no-await-in-loop
            await retryTemplateGeneration(sessionId);
            return true;
        } catch (retryError) {
            // The service did not take the retry: the next one comes later still.
            reason = Object.assign(retryError, {code: retryError.code || 'connection'});
        }
    }
    return false;
};

/**
 * The passes of one run: open the stream, apply what it says, and decide what the end of each pass means.
 *
 * @param {string} streamUrl
 * @param {Function} buildCourse
 * @param {number} sessionId
 * @param {Object} control The stop and resume of the run, from createRunControl.
 * @returns {Promise<Object>} The built course.
 */
const runPasses = async(streamUrl, buildCourse, sessionId, control) => {
    const progress = {total: 0, done: 0};
    // Reading the material is over once the first activity starts: the thread moves on to the
    // writing, so the step that was spinning becomes a past one.
    let writingAnnounced = false;
    const paintStageOfRun = async(key) => {
        if (key === 'activities' && !writingAnnounced) {
            writingAnnounced = true;
            milestone('courseai_template_log_writing');
        }
        await paintStage(key);
    };
    // Retries made by itself since the last time the run moved: a run that goes on resets the count.
    const run = {failures: 0, moved: false};
    for (;;) {
        let pass = null;
        try {
            if (control.takePendingStop()) {
                // Stopped before the stream even opened: it never does.
                pass = {outcome: 'stopped', data: null};
            } else {
                pass = await watchOnce(
                    streamUrl,
                    progress,
                    (eventData, eventProgress) => {
                        // Only an activity that was written counts as the run moving: notices, failed activities and the
                        // stages that come again do not, or a run that fails again and again would never run out of retries.
                        if (eventData.type === 'activity_progress_done') {
                            run.moved = true;
                        }
                        // The card stays until the run really moves or ends: the notices and the failures that come
                        // first are part of the same retry.
                        if (['activity_progress_done', 'completed', 'review_needed'].includes(eventData.type)) {
                            retriesOver();
                        }
                        if (eventData.type === 'course_configuration') {
                            applySessionName(sessionId, eventData);
                        }
                        return applyEvent(eventData, eventProgress, paintStageOfRun);
                    },
                    () => undefined,
                    (close) => control.attach(close)
                );
            }
            control.detach();
        } catch (error) {
            control.detach();
            // Whatever went wrong, what the run already made is kept, and it is tried again.
            control.end();
            hideWorkingIndicator();
            // Nothing is running any more, so nothing turns: the page reads as stopped while it waits.
            document.body.classList.add(PAUSED_CLASS);
            await paintStage('paused');
            if (run.moved) {
                run.failures = 0;
                run.moved = false;
            }
            if (await retryByItself(error, sessionId, () => ++run.failures, () => run.failures)) {
                document.body.classList.remove(PAUSED_CLASS);
                showGeneratingHeader((await getLabels()).title);
                await paintStage('connecting');
                resetStatus();
                control.begin();
                continue;
            }
            retriesOver();
            // The retries ran out, or the failure is not one that waiting mends: the person decides.
            if (!await askToRetry(error.code)) {
                closeView(error.message);
                throw new GenerationStopped(error.message);
            }
            document.body.classList.remove(PAUSED_CLASS);
            showGeneratingHeader((await getLabels()).title);
            await paintStage('connecting');
            resetStatus();
            try {
                await retryTemplateGeneration(sessionId);
            } catch (retryError) {
                // The retry itself failed: the same offer is made again, with the page stopped as before.
                document.body.classList.add(PAUSED_CLASS);
                await paintStage('paused');
                const again = await askToRetry('connection');
                if (!again) {
                    closeView(retryError.message);
                    throw new GenerationStopped(retryError.message);
                }
                document.body.classList.remove(PAUSED_CLASS);
                showGeneratingHeader((await getLabels()).title);
            }
            control.begin();
            continue;
        }
        const {outcome, data} = pass;

        if (outcome === 'stopped') {
            // eslint-disable-next-line no-await-in-loop
            await settleStop(sessionId, control);
            continue;
        }
        control.end();

        if (outcome === 'questions') {
            // eslint-disable-next-line no-await-in-loop
            await settleQuestions(data, sessionId);
            control.begin();
            continue;
        }

        if (outcome === 'completed') {
            // The result payload stays server-side: the browser only reports
            // that the run finished, and Moodle fetches it to build the course.
            markHeaderDone();
            // The run is over: the last step of the thread is a check, never a spinner.
            document.body.classList.remove(PAUSED_CLASS);
            document.body.classList.add(SETTLED_CLASS);
            // The working indicator gives way to the review: nothing is being
            // built until the professor has confirmed the course's name.
            hideWorkingIndicator();
            milestone('courseai_template_log_completed');
            return buildCourse();
        }

        await paintStage('reviewing');
        markHeaderDone();
        milestone('courseai_template_log_review_ready');
        // Waiting for the professor is not work in progress: nothing turns until the answer.
        document.body.classList.add(PAUSED_CLASS);
        // eslint-disable-next-line no-await-in-loop
        const decision = await askForDecision(data.generated_activities || []);
        document.body.classList.remove(PAUSED_CLASS);
        showGeneratingHeader((await getLabels()).title);
        control.begin();

        if (decision.action === 'accept') {
            milestone('courseai_template_log_approved', 'user', 'success');
            await paintStage('saving');
        } else {
            turn('user', 'user', decision.instruction);
            milestone('courseai_template_log_adjusting');
            await paintStage('activities');
        }

        // eslint-disable-next-line no-await-in-loop
        await sendTemplateReviewFeedback(
            sessionId,
            decision.action,
            decision.targetIds,
            decision.instruction
        );
    }
};
