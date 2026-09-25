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
 * The run is planned first, stops so the professor can read and approve the
 * plan, and only generates once it is approved; asking for changes plans
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
import {hideWorkingIndicator, showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';
import {ALL_STATUS_CLASSES, STATUS_CLASS, applyEvent} from 'local_coursegen/local/courseai/template/generation_events';
import {watchOnce} from 'local_coursegen/local/courseai/template/generation_watch';
import {hideHeader, markHeaderDone, showGeneratingHeader} from 'local_coursegen/local/courseai/template/generation_header';

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
const generatedRows = () => document.querySelectorAll('[data-generation-cmid]');

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
 * Run one generation, pausing for the professor's review, and resolve when
 * the course has been built.
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
        const {outcome, data} = await watchOnce(
            streamUrl,
            progress,
            (eventData, eventProgress) => applyEvent(eventData, eventProgress, paintStage),
            closeView
        );

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
