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
 * AI Course Creation Wizard entrypoint.
 *
 * @module     local_coursegen/courseai
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import * as CourseaiRepository from 'local_coursegen/repository/courseai';
import YUI from 'core/yui';
import * as markedModule from 'local_coursegen/marked';
import {sendPlanningFeedback, createCourse, getCourseSettings} from 'local_coursegen/repository/course';

import {
    parseCourseaiData,
    escapeHtml,
    getActivityLabels,
    getActivityIconUrl,
    getGenerateButtonHtml,
    formatTemplate,
} from 'local_coursegen/local/courseai/utils';
import {loadCourseaiStrings} from 'local_coursegen/local/courseai/i18n';
import {getCourseaiElements} from 'local_coursegen/local/courseai/selectors';
import {createInitialState} from 'local_coursegen/local/courseai/state';
import {createContextUi} from 'local_coursegen/local/courseai/ui-context';
import {createStepsUi} from 'local_coursegen/local/courseai/ui-steps';
import {createPlanningUi} from 'local_coursegen/local/courseai/ui-planning';
import {createDetailedUi} from 'local_coursegen/local/courseai/ui-detailed';
import {createStreamManager} from 'local_coursegen/local/courseai/stream';
import {createCourseaiActions} from 'local_coursegen/local/courseai/actions';
import {initSidebar} from 'local_coursegen/local/courseai/sidebar';
import {createProposalsUi} from 'local_coursegen/local/courseai/ui-proposals';
import {createRunPlanAction} from 'local_coursegen/local/courseai/actions/plan-action';
import {createSplitter} from 'local_coursegen/local/courseai/ui/splitter';
import {makeResumeHelpers} from 'local_coursegen/courseai/bootstrap/resume-helpers';
import {makeChecklistHelpers} from 'local_coursegen/courseai/bootstrap/checklist-helpers';
import {makeResumeFromSnapshot} from 'local_coursegen/courseai/bootstrap/resume-snapshot';
import {makeCreateCourseCallback} from 'local_coursegen/courseai/bootstrap/create-course-callback';
import {makeEmitLog, makeRenderPlanMarkdown} from 'local_coursegen/courseai/bootstrap/ui-helpers';
import {makeHydratePlan} from 'local_coursegen/courseai/bootstrap/hydrate-plan';
import {createExecutionControls} from 'local_coursegen/local/courseai/actions/execution-control';

/**
 * Initialize the courseai page.
 *
 * @param {Object} params
 */
export const init = async(params) => {
    try {
        const {guidelines, languages, defaultLang} = parseCourseaiData(params);
        const texts = await loadCourseaiStrings();
        const elements = getCourseaiElements();
        const state = createInitialState({defaultLang, guidelines, languages});

        // Decision log (§4) — instantiate before any module that needs it.
        const {emitLog} = makeEmitLog(state);

        const markedParser = markedModule.parse ? markedModule : markedModule.marked;
        const activityLabels = getActivityLabels(texts);
        const generateButtonHtml = getGenerateButtonHtml(texts);

        const contextUi = createContextUi({
            state,
            languages,
            defaultLang,
            elements,
            Notification,
            CourseaiRepository,
            YUI,
            texts,
        });

        const stepsUi = createStepsUi({
            state,
            elements,
            generateButtonHtml,
            texts,
        });

        const runPlanAction = createRunPlanAction({
            state,
            sendPlanningFeedback,
            openSSEStream: (url, retry, mode, keepPlan) =>
                streamManager.openSSEStream(url, retry, mode, keepPlan),
        });

        const detailedUi = createDetailedUi({
            state,
            elements,
            activityLabels,
            getActivityIconUrl,
            escapeHtml,
            switchPlanMode: stepsUi.switchPlanMode,
            setProgress: stepsUi.setProgress,
            texts,
            formatTemplate,
            runPlanAction,
            emitLog,
        });

        const proposalsUi = createProposalsUi({
            state,
            texts,
            formatTemplate,
            runPlanAction,
            emitLog,
        });

        const planningUi = createPlanningUi({
            state,
            elements,
            activityLabels,
            getActivityIconUrl,
            escapeHtml,
            setProgress: stepsUi.setProgress,
            updateDetailedHeaderStats: detailedUi.updateDetailedHeaderStats,
            texts,
            formatTemplate,
        });

        const renderPlanMarkdown = makeRenderPlanMarkdown({markedParser, state, elements});

        let actions = null;
        const streamLifecycle = {};
        const createCourseFromSession = makeCreateCourseCallback({
            elements,
            stepsUi,
            texts,
            getActions: () => actions,
        });

        const streamManager = createStreamManager({
            state,
            elements,
            stepsUi,
            planningUi,
            detailedUi,
            proposalsUi,
            renderPlanMarkdown,
            emitLog,
            createCourseFromSession,
            texts,
            onStreamStart: () => streamLifecycle.onStreamStart && streamLifecycle.onStreamStart(),
            onStreamEnd: () => streamLifecycle.onStreamEnd && streamLifecycle.onStreamEnd(),
        });

        stepsUi.bindCloseStream(streamManager.closeStream);

        actions = createCourseaiActions({
            state,
            elements,
            Notification,
            CourseaiRepository,
            sendPlanningFeedback,
            createCourse,
            getCourseSettings,
            updateGenerateButton: contextUi.updateGenerateButton,
            refreshChipsRow: contextUi.refreshChipsRow,
            refreshGuidelineChip: contextUi.refreshGuidelineChip,
            stepsUi,
            planningUi,
            streamManager,
            texts,
            formatTemplate,
            emitLog,
        });

        actions.bindEvents();

        const executionControls = createExecutionControls({state, elements, streamManager, texts, emitLog});
        executionControls.bindEvents();
        streamLifecycle.onStreamStart = executionControls.showStop;
        streamLifecycle.onStreamEnd = executionControls.hideControls;

        const {
            getResumeSessionId,
            setResumeBootLoading,
            setPlanningStreamVisible,
            parseJsonField,
            applyCourseTitleToHeader,
            buildCourseUrlFromResume,
            normalizeSnapshotStatus,
        } = makeResumeHelpers({state, elements, texts, params});

        const {
            buildSectionsFromDetailedPlan,
            restoreAdjustmentHistory,
        } = makeChecklistHelpers({state, elements, texts});

        const hydrateDetailedPlanFromSnapshot = makeHydratePlan(detailedUi);
        const resumeSessionId = getResumeSessionId();

        const resumeFromSnapshot = makeResumeFromSnapshot({
            state,
            elements,
            CourseaiRepository,
            stepsUi,
            planningUi,
            detailedUi,
            streamManager,
            actions,
            parseJsonField,
            normalizeSnapshotStatus,
            buildSectionsFromDetailedPlan,
            buildCourseUrlFromResume,
            applyCourseTitleToHeader,
            setPlanningStreamVisible,
            hydrateDetailedPlanFromSnapshot,
            restoreAdjustmentHistory,
            resumeSessionId,
            emitLog,
            texts,
        });

        try {
            setResumeBootLoading(true);
            const resumed = await resumeFromSnapshot();
            if (!resumed && elements.contextView) {
                elements.contextView.style.display = '';
            }
        } catch (resumeError) {
            if (elements.contextView) {
                elements.contextView.style.display = '';
            }
        } finally {
            setResumeBootLoading(false);
        }

        contextUi.renderGuidelineList();
        stepsUi.updateFlowNav();
        contextUi.updateGenerateButton();

        // Initialize sidebar.
        initSidebar(state, actions.resetForAnotherCourse);

        // Initialize resizable splitter (§2.1).
        createSplitter({
            workspace: document.getElementById('courseaiWorkspace'),
            divider: document.getElementById('cgSplitter'),
        });
    } catch (error) {
        Notification.exception(error);
    }
};
