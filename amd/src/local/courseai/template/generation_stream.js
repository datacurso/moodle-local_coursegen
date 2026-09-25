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

import {askForDecision, clearPlans} from 'local_coursegen/local/courseai/template/plan_review';
import {
    announceTemplate,
    milestone,
    resetThread,
    restorePicker,
    turn,
} from 'local_coursegen/local/courseai/template/thread';
import {refreshPreviewLinks} from 'local_coursegen/local/courseai/template/preview';
import {sendTemplatePlanningFeedback} from 'local_coursegen/local/courseai/template/repository';
import {hideWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {
    ALL_STATUS_CLASSES,
    STATUS_CLASS,
    applyEvent,
    generatedRows,
    getLabels,
    paintStage,
} from 'local_coursegen/local/courseai/template/stream_events';

/**
 * Put the page in its generating state: header visible and spinning, every
 * activity the AI will generate dimmed and waiting, edit controls gone.
 *
 * @param {Object} context {prompt, templateName} for the opening turns.
 */
const openView = async(context) => {
    document.body.classList.add('cg-generating');

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
    milestone('courseai_template_log_planning');
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
    const header = document.getElementById('tplGenHeader');
    if (header) {
        header.hidden = true;
    }
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
 * Watch one pass of the stream.
 *
 * A pass ends in one of three ways: the graph pauses for the review, the run
 * completes, or it fails. The first two are not the end of the work, only of
 * this connection, which is why the caller loops.
 *
 * @param {string} streamUrl
 * @param {Object} progress Mutable {total, done} counters.
 * @returns {Promise<Object>} {outcome: 'review'|'completed', data}
 */
const watchOnce = (streamUrl, progress) => new Promise((resolve, reject) => {
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
        if (outcome === 'review' || outcome === 'completed') {
            // The stream is closed on both. A pause left open would be
            // reconnected by EventSource, which resumes the graph from the
            // same point and re-emits the same pause, forever.
            finish(() => resolve({outcome, data}));
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

/**
 * Run one generation, pausing for the professor's review, and resolve when
 * the course has been built.
 *
 * The run is planned first, stops so the plan can be read, and only generates
 * once it is approved. Asking for changes plans again and stops again, which
 * is why this loops instead of running once.
 *
 * @param {string} streamUrl SSE endpoint returned by start_template_generation.
 * @param {Function} buildCourse Called once the run completes; resolves to {courseurl}.
 * @param {number} sessionId Session the review answers belong to.
 * @param {Object} context {prompt, templateName}, for the opening turns.
 * @returns {Promise<Object>} The built course, as buildCourse resolved it.
 */
export const runGenerationStream = async(streamUrl, buildCourse, sessionId, context) => {
    await openView(context);
    clearPlans();
    await paintStage('connecting');

    const progress = {total: 0, done: 0};
    for (;;) {
        // eslint-disable-next-line no-await-in-loop
        const {outcome, data} = await watchOnce(streamUrl, progress);

        if (outcome === 'completed') {
            // The result payload stays server-side: the browser only reports
            // that the run finished, and Moodle fetches it to build the course.
            markHeaderDone();
            paintStage('building');
            milestone('courseai_template_log_completed');
            return buildCourse();
        }

        await paintStage('reviewing');
        milestone('courseai_template_log_plan_ready');
        // eslint-disable-next-line no-await-in-loop
        const decision = await askForDecision(data.template_plan || []);

        if (decision.action === 'accept') {
            milestone('courseai_template_log_approved', 'user', 'success');
            milestone('courseai_template_log_generating');
            await paintStage('style');
        } else {
            turn('user', 'user', decision.instruction);
            milestone('courseai_template_log_adjusting');
            await paintStage('planning');
        }

        // eslint-disable-next-line no-await-in-loop
        await sendTemplatePlanningFeedback(
            sessionId,
            decision.action,
            decision.targetIds,
            decision.instruction
        );
    }
};
