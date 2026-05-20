/* eslint-disable */
// This file is part of Moodle - http://moodle.org/

/**
 * AI Course Creation Wizard entrypoint.
 *
 * @module     local_coursegen/courseai
 */

import Notification from 'core/notification';
import * as CourseaiRepository from 'local_coursegen/repository/courseai';
import YUI from 'core/yui';
import * as markedModule from 'local_coursegen/marked';
import {sendPlanningFeedback, createCourse} from 'local_coursegen/repository/course';

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
import {regenerateDetailedItem} from 'local_coursegen/repository/course';
import {createStreamManager} from 'local_coursegen/local/courseai/stream';
import {createCourseaiActions} from 'local_coursegen/local/courseai/actions';
import {initSidebar} from 'local_coursegen/local/courseai/sidebar';

/**
 * Initialize the courseai page.
 *
 * @param {Object} params
 */
export const init = async(params) => {
    try {
        window.console.log('CourseAI initialized', params);

        const {guidelines, languages, defaultLang} = parseCourseaiData(params);
        const texts = await loadCourseaiStrings();
        const elements = getCourseaiElements();
        const state = createInitialState({defaultLang, guidelines, languages});

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
            regenerateDetailedItem,
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

        const renderPlanMarkdown = () => {
            if (!elements.planMarkdown) {
                return;
            }
            const html = markedParser.parse ? markedParser.parse(state.planBuffer || '') : '';
            elements.planMarkdown.innerHTML = html;
            if (elements.pcDetailsPanel && state.planDetailsOpen) {
                elements.pcDetailsPanel.scrollTop = elements.pcDetailsPanel.scrollHeight;
            }
        };

        let actions = null;
        const streamManager = createStreamManager({
            state,
            elements,
            stepsUi,
            planningUi,
            detailedUi,
            renderPlanMarkdown,
            createCourseFromSession: async() => {
                if (actions) {
                    await actions.createCourseFromSession();
                }
            },
            texts,
        });

        stepsUi.bindCloseStream(streamManager.closeStream);

        actions = createCourseaiActions({
            state,
            elements,
            Notification,
            CourseaiRepository,
            sendPlanningFeedback,
            createCourse,
            updateGenerateButton: contextUi.updateGenerateButton,
            refreshChipsRow: contextUi.refreshChipsRow,
            refreshGuidelineChip: contextUi.refreshGuidelineChip,
            stepsUi,
            planningUi,
            streamManager,
            texts,
            formatTemplate,
        });

        actions.bindEvents();

        contextUi.renderGuidelineList();
        stepsUi.updateFlowNav();
        contextUi.updateGenerateButton();

        // Initialize sidebar.
        initSidebar(state, actions.resetForAnotherCourse);
    } catch (error) {
        Notification.exception(error);
    }
};
