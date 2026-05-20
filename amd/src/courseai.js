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

        const getResumeSessionId = () => {
            const fromParams = Number(params?.resumesessionid || 0);
            if (fromParams > 0) {
                return fromParams;
            }

            const fromUrl = Number(new URLSearchParams(window.location.search).get('sessionid') || 0);
            return fromUrl > 0 ? fromUrl : 0;
        };

        const resumeLoadingView = document.getElementById('resumeLoadingView');
        const setResumeBootLoading = (loading) => {
            if (!resumeLoadingView) {
                return;
            }
            resumeLoadingView.style.display = loading ? '' : 'none';
        };

        const setPlanningStreamVisible = () => {
            const loadingEl = document.getElementById('planningLoading');
            const streamContentEl = document.getElementById('planningStreamContent');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }
            if (streamContentEl) {
                streamContentEl.style.display = '';
            }
        };

        const parseJsonField = (value, fallback) => {
            if (!value) {
                return fallback;
            }

            try {
                return JSON.parse(value);
            } catch (error) {
                return fallback;
            }
        };

        const normalizeSnapshotStatus = (rawStatus) => {
            const upper = String(rawStatus || '').toUpperCase();
            if (!upper) {
                return '';
            }

            const parts = upper.split('.');
            return parts[parts.length - 1] || upper;
        };

        const buildSectionsFromDetailedPlan = (detailedSections) => {
            if (!Array.isArray(detailedSections)) {
                return [];
            }

            return detailedSections.map((section, sectionIndex) => ({
                section_index: section.section_index ?? sectionIndex,
                name: section.name || `${texts.courseai_section_label} ${sectionIndex + 1}`,
                description: section.description || '',
                activities: (Array.isArray(section.activities) ? section.activities : []).map((activity, activityIndex) => ({
                    activity_type: activity.activity_type || activity.type || 'page',
                    title: activity.title || activity.name || `${texts.courseai_activity_default} ${activityIndex + 1}`,
                    description:
                        activity.description
                        || activity?.detailed_plan?.activity_description
                        || '',
                    detailed_plan: activity.detailed_plan || {},
                })),
            }));
        };

        const hydrateDetailedPlanFromSnapshot = (sections) => {
            detailedUi.initDetailedPlanView({sections});
            sections.forEach((section, sectionIndex) => {
                (section.activities || []).forEach((activity, activityIndex) => {
                    detailedUi.handleDetailedPlanActivity({
                        section_index: section.section_index ?? sectionIndex,
                        activity_index: activityIndex,
                        title: activity.title,
                        activity_type: activity.activity_type,
                        data: activity.detailed_plan || {},
                    });
                });
            });
        };

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

        const resumeSessionId = getResumeSessionId();

        const createRoundChecklistElement = (roundData) => {
            const sections = Array.isArray(roundData?.sections) ? roundData.sections : [];
            if (!sections.length) {
                return null;
            }

            const checklist = document.createElement('div');
            checklist.className = 'courseai-checklist';
            if (typeof roundData?.round !== 'undefined') {
                checklist.setAttribute('data-round', String(roundData.round));
            }

            const label = document.createElement('span');
            label.className = 'courseai-checklist-label';
            label.textContent = texts.courseai_checklist_label || 'Course sections';
            checklist.appendChild(label);

            const list = document.createElement('ul');
            list.className = 'courseai-checklist-list';

            sections.forEach((section) => {
                const item = document.createElement('li');
                item.className = 'courseai-checklist-item is-done';
                item.setAttribute('data-section-index', String(section.section_index || 0));
                const total = Number(section.total || 0);
                const done = Number(section.done || 0);

                const check = document.createElement('span');
                check.className = 'courseai-checklist-check';
                check.innerHTML = '<svg class="spinner-icon" viewBox="0 0 24 24">'
                    + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>'
                    + '<svg class="check-icon" viewBox="0 0 24 24">'
                    + '<polyline points="20 6 9 17 4 12"></polyline></svg>';

                const name = document.createElement('span');
                name.className = 'courseai-checklist-name';
                name.textContent = String(section.name || '');

                const meta = document.createElement('span');
                meta.className = 'courseai-checklist-meta';
                meta.textContent = `${done}/${total}`;

                item.appendChild(check);
                item.appendChild(name);
                item.appendChild(meta);
                list.appendChild(item);
            });

            checklist.appendChild(list);
            return checklist;
        };

        const restoreAdjustmentHistory = (messages, planningRounds = []) => {
            if (!elements.adjustmentHistory) {
                return;
            }

            const humanMessages = Array.isArray(messages)
                ? messages.filter((message, index) => index > 0 && message.role === 'human' && message.content)
                : [];

            if (!humanMessages.length) {
                elements.adjustmentHistory.classList.add('hidden');
                elements.adjustmentHistory.innerHTML = '';
                return;
            }

            elements.adjustmentHistory.innerHTML = '';
            const roundsForHistory = Array.isArray(planningRounds)
                ? planningRounds.slice(Math.max(0, planningRounds.length - humanMessages.length))
                : [];

            humanMessages.forEach((message, idx) => {
                const round = idx + 1;
                const roundContainer = document.createElement('div');
                roundContainer.className = 'courseai-round';
                roundContainer.setAttribute('data-round', String(round));

                const msgEl = document.createElement('div');
                msgEl.className = 'courseai-chat-history';

                const bubble = document.createElement('div');
                bubble.className = 'courseai-chat-message courseai-chat-message--user';
                const text = document.createElement('p');
                text.textContent = message.content;
                bubble.appendChild(text);
                msgEl.appendChild(bubble);

                const responseSlot = document.createElement('div');
                responseSlot.className = 'courseai-round-response';
                responseSlot.setAttribute('data-round', String(round));

                roundContainer.appendChild(msgEl);
                roundContainer.appendChild(responseSlot);

                const roundChecklist = createRoundChecklistElement(roundsForHistory[idx]);
                if (roundChecklist) {
                    responseSlot.appendChild(roundChecklist);
                }

                elements.adjustmentHistory.appendChild(roundContainer);
            });

            elements.adjustmentHistory.classList.remove('hidden');
            state.generationRound = humanMessages.length;
        };

        const resumeFromSnapshot = async() => {
            if (resumeSessionId <= 0) {
                return false;
            }

            const resume = await CourseaiRepository.getSessionState(resumeSessionId);
            if (!resume || !resume.success) {
                return false;
            }

            const coursedata = parseJsonField(resume.coursedatajson, {});
            const snapshot = parseJsonField(resume.snapshotjson, {});
            const status = normalizeSnapshotStatus(snapshot.status);

            state.sessionid = Number(resume.recordid || resumeSessionId);
            state.threadid = String(resume.sessionid || '');
            state.streamingurl = String(resume.streamingurl || '');
            state.lang = snapshot?.request_config?.lang || coursedata.local_coursegen_lang || state.defaultLang;
            state.withImages = Boolean(
                snapshot?.request_config?.with_images
                ?? coursedata.local_coursegen_with_images
                ?? false
            );

            const initialPrompt =
                snapshot?.messages?.[0]?.content
                || coursedata.local_coursegen_custom_prompt
                || '';
            state.initialPrompt = initialPrompt;

            if (elements.promptInput) {
                elements.promptInput.value = initialPrompt;
            }
            if (elements.initialPromptText) {
                elements.initialPromptText.textContent = initialPrompt;
            }
            if (elements.initialPromptHistory) {
                elements.initialPromptHistory.classList.toggle('hidden', !initialPrompt);
            }
            if (elements.langSelect) {
                elements.langSelect.value = state.lang;
            }
            if (elements.btnWithImages) {
                elements.btnWithImages.checked = state.withImages;
            }
            if (elements.imgToggleWrap) {
                elements.imgToggleWrap.classList.toggle('on', state.withImages);
            }

            planningUi.syncCompactChatState();
            restoreAdjustmentHistory(snapshot?.messages || [], snapshot?.planning_rounds || []);

            const detailedSections = Array.isArray(snapshot.detailed_plan_sections)
                ? snapshot.detailed_plan_sections
                : [];
            const sectionsForUi = buildSectionsFromDetailedPlan(detailedSections);

            if (sectionsForUi.length > 0) {
                state.latestInitialSections = sectionsForUi;
                state.totalSections = sectionsForUi.length;
                state.totalActivities = sectionsForUi.reduce(
                    (acc, section) => acc + ((section.activities || []).length),
                    0
                );
            }

            if (status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST') {
                stepsUi.transitionToPlanning();
                setPlanningStreamVisible();
                hydrateDetailedPlanFromSnapshot(sectionsForUi);
                planningUi.showReviewActions('detailed');
                return true;
            }

            if (status === 'GENERATING' || status === 'PLANNING_ACCEPT') {
                stepsUi.transitionToPlanning();
                stepsUi.setStepState('planning', 'done');
                stepsUi.setStepState('generating', 'active');
                state.currentStage = 'generating';
                state.phase4TotalActivities = state.totalActivities;
                streamManager.openSSEStream(state.streamingurl, 0, 'generating');
                return true;
            }

            if (status === 'PLANNING' || status === 'PENDING') {
                stepsUi.transitionToPlanning();
                streamManager.openSSEStream(state.streamingurl, 0, 'planning');
                return true;
            }

            if (status === 'COMPLETED') {
                stepsUi.transitionToPlanning();
                setPlanningStreamVisible();
                if (sectionsForUi.length > 0) {
                    hydrateDetailedPlanFromSnapshot(sectionsForUi);
                }
                planningUi.showReviewActions('detailed');
                stepsUi.setStepState('planning', 'done');
                stepsUi.setStepState('generating', 'done');
                state.currentStage = 'completed';
                stepsUi.setProgress(100);
                stepsUi.updateFlowNav();
                return true;
            }

            return false;
        };

        try {
            setResumeBootLoading(true);
            const resumed = await resumeFromSnapshot();
            if (!resumed && elements.contextView) {
                elements.contextView.style.display = '';
            }
        } catch (resumeError) {
            window.console.warn('Unable to resume course session', resumeError);
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
    } catch (error) {
        Notification.exception(error);
    }
};
