/* eslint-disable */
// This file is part of Moodle - http://moodle.org/

/**
 * AI Course Creation Wizard entrypoint.
 *
 * @module     local_coursegen/wizard
 */

import Notification from 'core/notification';
import * as WizardRepository from 'local_coursegen/repository/wizard';
import YUI from 'core/yui';
import * as markedModule from 'local_coursegen/marked';
import {sendPlanningFeedback, createCourse} from 'local_coursegen/repository/course';

import {
    parseWizardData,
    escapeHtml,
    getActivityLabels,
    getGenerateButtonHtml,
    formatTemplate,
} from 'local_coursegen/local/wizard/utils';
import {loadWizardStrings} from 'local_coursegen/local/wizard/i18n';
import {getWizardElements} from 'local_coursegen/local/wizard/selectors';
import {createInitialState} from 'local_coursegen/local/wizard/state';
import {createContextUi} from 'local_coursegen/local/wizard/ui-context';
import {createStepsUi} from 'local_coursegen/local/wizard/ui-steps';
import {createPlanningUi} from 'local_coursegen/local/wizard/ui-planning';
import {createDetailedUi} from 'local_coursegen/local/wizard/ui-detailed';
import {createStreamManager} from 'local_coursegen/local/wizard/stream';
import {createWizardActions} from 'local_coursegen/local/wizard/actions';

/**
 * Initialize the wizard page.
 *
 * @param {Object} params
 */
export const init = async(params) => {
    try {
        window.console.log('Wizard initialized', params);

        const {guidelines, languages, defaultLang} = parseWizardData(params);
        const texts = await loadWizardStrings();
        const elements = getWizardElements();
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
            WizardRepository,
            YUI,
            texts,
        });

        const stepsUi = createStepsUi({
            state,
            elements,
            generateButtonHtml,
            texts,
        });

        const planningUi = createPlanningUi({
            state,
            elements,
            activityLabels,
            escapeHtml,
            setProgress: stepsUi.setProgress,
            texts,
            formatTemplate,
        });

        const detailedUi = createDetailedUi({
            state,
            elements,
            activityLabels,
            escapeHtml,
            switchPlanMode: stepsUi.switchPlanMode,
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

        actions = createWizardActions({
            state,
            elements,
            Notification,
            WizardRepository,
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
    } catch (error) {
        Notification.exception(error);
    }
};
