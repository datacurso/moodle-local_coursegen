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
import {createLog} from 'local_coursegen/local/courseai/ui/log';

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

        const applyCourseTitleToHeader = () => {
            const title = String(state.courseTitle || '').trim();
            if (!title || !elements.prvHeaderTitle) {
                return;
            }
            elements.prvHeaderTitle.textContent = title;
        };

        const buildCourseUrlFromResume = (resume) => {
            const explicitUrl = String(resume?.courseurl || '').trim();
            if (explicitUrl) {
                return explicitUrl;
            }

            const courseId = Number(resume?.courseid || 0);
            if (courseId <= 0) {
                return '';
            }

            const baseUrl = String(window?.M?.cfg?.wwwroot || '').replace(/\/$/, '');
            if (!baseUrl) {
                return `/course/view.php?id=${courseId}`;
            }

            return `${baseUrl}/course/view.php?id=${courseId}`;
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

            return detailedSections
                .filter((section) => !section?.deleted)
                .map((section, sectionIndex) => ({
                    section_index: section.section_index ?? sectionIndex,
                    name: section.name || `${texts.courseai_section_label} ${sectionIndex + 1}`,
                    description: section.description || '',
                    activities: (Array.isArray(section.activities) ? section.activities : [])
                        .filter((activity) => !activity?.deleted)
                        .map((activity, activityIndex) => ({
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

        // Decision log (§4) — instantiate before any module that needs it.
        const logContainer = document.getElementById('cgLog');
        const logSection = document.getElementById('cgLogSection');
        const courseaiLog = createLog({container: logContainer});

        /**
         * Show the log section and emit an entry.
         *
         * @param {Object} params
         */
        const emitLog = (params) => {
            if (logSection && logSection.style.display === 'none') {
                logSection.style.display = '';
            }
            courseaiLog.add(params);
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

        const runPlanAction = createRunPlanAction({
            state,
            sendPlanningFeedback,
            openSSEStream: (url, retry, mode) => streamManager.openSSEStream(url, retry, mode),
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
            proposalsUi,
            renderPlanMarkdown,
            emitLog,
            createCourseFromSession: async() => {
                if (actions) {
                    // Advance progress to the review phase so it doesn't appear stuck.
                    stepsUi.setProgress(92);
                    if (elements.pcStep) {
                        elements.pcStep.textContent = texts.courseai_review_step_label;
                    }
                    if (elements.pcTitle) {
                        elements.pcTitle.textContent = texts.courseai_review_title;
                    }
                    if (elements.pcSubtitle) {
                        elements.pcSubtitle.textContent = texts.courseai_review_subtitle;
                    }
                    // Swap the checkmark icon for an edit icon to signal user action needed.
                    const planningSpinner = document.getElementById('planningSpinner');
                    const planningCheckIcon = document.getElementById('planningCheckIcon');
                    const pcIconWrap = document.getElementById('pcIconWrap');
                    if (planningSpinner) {
                        planningSpinner.style.display = 'none';
                    }
                    if (planningCheckIcon) {
                        planningCheckIcon.style.display = 'none';
                    }
                    if (pcIconWrap) {
                        const existingEdit = document.getElementById('planningEditIcon');
                        if (!existingEdit) {
                            const editSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                            editSvg.setAttribute('id', 'planningEditIcon');
                            editSvg.setAttribute('width', '20');
                            editSvg.setAttribute('height', '20');
                            editSvg.setAttribute('viewBox', '0 0 24 24');
                            editSvg.setAttribute('fill', 'none');
                            editSvg.setAttribute('stroke', 'currentColor');
                            editSvg.setAttribute('stroke-width', '2');
                            editSvg.setAttribute('stroke-linecap', 'round');
                            editSvg.setAttribute('stroke-linejoin', 'round');
                            editSvg.style.color = '#fff';
                            const path1 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                            path1.setAttribute('d', 'M12 20h9');
                            const path2 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                            path2.setAttribute('d', 'M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z');
                            editSvg.appendChild(path1);
                            editSvg.appendChild(path2);
                            pcIconWrap.appendChild(editSvg);
                        } else {
                            existingEdit.style.display = '';
                        }
                    }
                    // Show the course review panel before creating.
                    const overrides = await actions.showCourseReviewPanel();
                    if (overrides === null) {
                        return;
                    }
                    await actions.createCourseFromSession(overrides);
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

        const resumeSessionId = getResumeSessionId();

        const getMessageRole = (message) => String(message?.role || '').toLowerCase();

        const isAdjustmentMessage = (message, index) => {
            if (!message || index === 0) {
                return false;
            }

            const role = getMessageRole(message);
            if (role === 'human' || role === 'user') {
                return Boolean(String(message.content || '').trim());
            }

            return false;
        };

        const buildChecklistItem = (section) => {
            const item = document.createElement('li');
            item.className = 'courseai-checklist-item is-done';
            item.setAttribute('data-section-index', String(section.section_index || 0));

            const check = document.createElement('span');
            check.className = 'courseai-checklist-check';
            check.innerHTML = '<svg class="spinner-icon" viewBox="0 0 24 24">'
                + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>'
                + '<svg class="check-icon" viewBox="0 0 24 24">'
                + '<polyline points="20 6 9 17 4 12"></polyline></svg>';

            const name = document.createElement('span');
            name.className = 'courseai-checklist-name';
            name.textContent = String(section.name || '');

            item.appendChild(check);
            item.appendChild(name);

            return item;
        };

        const buildChecklistRoundFromSections = (sections) => {
            if (!Array.isArray(sections) || sections.length === 0) {
                return null;
            }

            const checklistSections = sections.map((section, index) => {
                const activities = Array.isArray(section?.activities) ? section.activities : [];
                const total = activities.length;

                return {
                    section_index: Number(section?.section_index ?? index),
                    name: String(section?.name || ''),
                    done: total,
                    total,
                };
            });

            return {
                sections: checklistSections,
            };
        };

        const renderInitialChecklist = (roundData) => {
            if (!elements.checklistList || !elements.checklist) {
                return;
            }

            const sections = Array.isArray(roundData?.sections) ? roundData.sections : [];
            elements.checklistList.innerHTML = '';

            if (!sections.length) {
                elements.checklist.classList.add('hidden');
                return;
            }

            sections.forEach((section) => {
                elements.checklistList.appendChild(buildChecklistItem(section));
            });

            elements.checklist.classList.remove('hidden');
        };

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
            label.textContent = texts.courseai_checklist_label;
            checklist.appendChild(label);

            const list = document.createElement('ul');
            list.className = 'courseai-checklist-list';

            sections.forEach((section) => list.appendChild(buildChecklistItem(section)));

            checklist.appendChild(list);
            return checklist;
        };

        const restoreAdjustmentHistory = (messages, planningRounds = [], fallbackSections = []) => {
            if (!elements.adjustmentHistory) {
                return;
            }

            const humanMessages = Array.isArray(messages)
                ? messages.filter((message, index) => isAdjustmentMessage(message, index))
                : [];

            const rounds = Array.isArray(planningRounds) ? planningRounds : [];
            const fallbackRound = buildChecklistRoundFromSections(fallbackSections);
            const roundsByNumber = rounds.reduce((map, roundData, index) => {
                const roundNumber = Number(roundData?.round ?? index + 1);
                if (!Number.isNaN(roundNumber) && roundNumber >= 0) {
                    map[roundNumber] = roundData;
                }
                return map;
            }, {});

            renderInitialChecklist(roundsByNumber[1] || fallbackRound);

            if (!humanMessages.length) {
                elements.adjustmentHistory.classList.add('hidden');
                elements.adjustmentHistory.innerHTML = '';
                return;
            }

            elements.adjustmentHistory.innerHTML = '';

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

                const roundChecklist = createRoundChecklistElement(roundsByNumber[round + 1] || fallbackRound);
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
            state.courseTitle = String(snapshot?.course_identity?.fullname || '').trim();

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

            const detailedSections = Array.isArray(snapshot.detailed_plan_sections)
                ? snapshot.detailed_plan_sections
                : [];
            const sectionsForUi = buildSectionsFromDetailedPlan(detailedSections);

            restoreAdjustmentHistory(snapshot?.messages || [], snapshot?.planning_rounds || [], sectionsForUi);

            if (sectionsForUi.length > 0) {
                state.latestInitialSections = sectionsForUi;
                state.totalSections = sectionsForUi.length;
                state.totalActivities = sectionsForUi.reduce(
                    (acc, section) => acc + ((section.activities || []).length),
                    0
                );
            }

            const sessionStatus = Number(resume?.sessionstatus || 0);
            const hasCreatedStatus = sessionStatus === 3;
            const hasCreatedCourse = Number(resume?.courseid || 0) > 0;
            const isCreated = Boolean(resume?.iscreated) || hasCreatedStatus || hasCreatedCourse;

            if (isCreated) {
                stepsUi.transitionToPlanning();
                setPlanningStreamVisible();
                applyCourseTitleToHeader();
                if (sectionsForUi.length > 0) {
                    hydrateDetailedPlanFromSnapshot(sectionsForUi);
                }
                if (typeof detailedUi.enableAllActionControls === 'function') {
                    detailedUi.enableAllActionControls();
                }

                actions.showCompletionView({
                    success: true,
                    courseid: Number(resume?.courseid || 0),
                    courseurl: buildCourseUrlFromResume(resume),
                });
                return true;
            }

            if (status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST') {
                stepsUi.transitionToPlanning();
                setPlanningStreamVisible();
                applyCourseTitleToHeader();
                hydrateDetailedPlanFromSnapshot(sectionsForUi);
                if (typeof detailedUi.enableAllActionControls === 'function') {
                    detailedUi.enableAllActionControls();
                }
                planningUi.showReviewActions('detailed');
                return true;
            }

            if (status === 'GENERATING' || status === 'PLANNING_ACCEPT') {
                stepsUi.transitionToPlanning();
                applyCourseTitleToHeader();
                stepsUi.setStepState('planning', 'done');
                stepsUi.setStepState('generating', 'active');
                state.currentStage = 'generating';
                state.phase4TotalActivities = state.totalActivities;
                streamManager.openSSEStream(state.streamingurl, 0, 'generating');
                return true;
            }

            if (status === 'PLANNING' || status === 'PENDING') {
                stepsUi.transitionToPlanning();
                applyCourseTitleToHeader();
                streamManager.openSSEStream(state.streamingurl, 0, 'planning');
                return true;
            }

            if (status === 'COMPLETED') {
                stepsUi.transitionToPlanning();
                setPlanningStreamVisible();
                applyCourseTitleToHeader();
                if (sectionsForUi.length > 0) {
                    hydrateDetailedPlanFromSnapshot(sectionsForUi);
                }
                if (typeof detailedUi.enableAllActionControls === 'function') {
                    detailedUi.enableAllActionControls();
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
