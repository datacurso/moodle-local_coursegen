// This file is part of Moodle - http://moodle.org/

/**
 * Wizard action handlers.
 *
 * @module     local_coursegen/local/wizard/actions
 */

/**
 * Create wizard actions and event bindings.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createWizardActions = (deps) => {
    const {
        state,
        elements,
        Notification,
        WizardRepository,
        sendPlanningFeedback,
        createCourse,
        updateGenerateButton,
        refreshChipsRow,
        refreshGuidelineChip,
        stepsUi,
        planningUi,
        streamManager,
        texts,
        formatTemplate,
    } = deps;

    const {
        promptInput,
        btnGenerate,
        adjustInput,
        btnApprove,
        btnAdjust,
        btnAdjustCancel,
        btnAdjustSend,
        adjustPanel,
        planActions,
        planningSpinner,
        pcSubtitle,
        btnBackFlow,
        btnCancelFlow,
        planningNavRow,
        pcToggleBtn,
        pcDetailsPanel,
        pcChevron,
        planningProgressCard,
        completionView,
        completionSummary,
        btnOpenMoodleCourse,
        btnCreateAnotherCourse,
        btnWithImages,
        imgToggleWrap,
        langSelect,
    } = elements;

    const getSummaryCounts = () => {
        if (state.completionStats) {
            return state.completionStats;
        }

        const units = state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0;
        const activities = state.totalActivities || state.detailedTotal || 0;
        const images = Object.keys(state.selectedDetailedImages || {})
            .filter((id) => state.selectedDetailedImages[id] !== false).length;

        return {units, activities, images};
    };

    const buildCompletionSummary = () => {
        const {units, activities, images} = getSummaryCounts();
        if (state.withImages) {
            return formatTemplate(texts.wizard_completion_summary_with_images, {
                units,
                activities,
                images,
            });
        }

        return formatTemplate(texts.wizard_completion_summary_no_images, {
            units,
            activities,
        });
    };

    const showCompletionView = (result) => {
        state.createdCourseResult = result || null;
        state.createdCourseUrl = result?.courseurl || '';
        state.currentStage = 'completed';

        if (completionSummary) {
            completionSummary.textContent = buildCompletionSummary();
        }
        if (planningProgressCard) {
            planningProgressCard.style.display = 'none';
        }
        if (elements.planReviewCard) {
            elements.planReviewCard.style.display = 'none';
        }
        if (planActions) {
            planActions.style.display = 'none';
        }
        if (adjustPanel) {
            adjustPanel.style.display = 'none';
        }
        if (planningNavRow) {
            planningNavRow.style.display = 'none';
        }
        if (completionView) {
            completionView.style.display = 'flex';
        }
        if (btnOpenMoodleCourse) {
            btnOpenMoodleCourse.disabled = !state.createdCourseUrl;
        }

        stepsUi.setStepState('generating', 'done');
        stepsUi.updateFlowNav();
    };

    const resetForAnotherCourse = () => {
        state.sessionid = 0;
        state.threadid = '';
        state.streamingurl = '';
        state.selectedGuidelineId = null;
        state.syllabusFile = null;
        state.syllabusFilename = null;
        state.draftitemid = null;
        state.withImages = false;
        state.lang = state.defaultLang;
        state.completionStats = null;
        state.createdCourseUrl = '';
        state.createdCourseResult = null;

        if (promptInput) {
            promptInput.value = '';
        }
        if (langSelect) {
            langSelect.value = state.lang;
        }
        if (btnWithImages) {
            btnWithImages.checked = false;
        }
        if (imgToggleWrap) {
            imgToggleWrap.classList.remove('on');
        }

        const chipSyllabus = document.getElementById('chipSyllabus');
        if (chipSyllabus) {
            chipSyllabus.classList.add('hidden');
        }
        const chipSyllabusName = document.getElementById('chipSyllabusName');
        if (chipSyllabusName) {
            chipSyllabusName.textContent = '';
        }

        refreshGuidelineChip();
        refreshChipsRow();
        stepsUi.backToContext();
        updateGenerateButton();
    };

    const createCourseFromSession = async() => {
        if (!state.sessionid) {
            return;
        }

        let progressInterval = null;

        try {
            if (elements.pcStep) {
                elements.pcStep.textContent = texts.wizard_state_completed;
            }
            if (elements.pcTitle) {
                elements.pcTitle.textContent = texts.wizard_course_creating;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.wizard_course_creating_subtitle;
            }

            // Continue progress from content generation phase (should be around 90%)
            // Quick final push: currentProgress → 95%
            const startProgress = state.contentGenerationCurrent && state.detailedTotal > 0
                ? Math.min(90, (state.contentGenerationCurrent / state.detailedTotal) * 90)
                : 0;
            const targetProgress = 95;
            const duration = 2000; // 2 seconds for final push
            const intervalMs = 100;
            const startTime = Date.now();

            progressInterval = setInterval(() => {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(1, elapsed / duration);
                // Ease-out for smooth finish
                const eased = 1 - Math.pow(1 - progress, 3);
                const currentProgress = startProgress + (eased * (targetProgress - startProgress));
                stepsUi.setProgress(Math.round(currentProgress));

                // Stop interval when target reached
                if (currentProgress >= targetProgress) {
                    clearInterval(progressInterval);
                }
            }, intervalMs);

            const result = await createCourse({recordid: state.sessionid});

            // Stop simulation and jump to 100%
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            stepsUi.setProgress(100);

            if (!result || !result.success) {
                throw new Error(result?.message || texts.wizard_error_create_course);
            }

            showCompletionView(result);
            return result;
        } catch (error) {
            if (progressInterval) {
                clearInterval(progressInterval);
            }

            if (elements.pcStep) {
                elements.pcStep.textContent = texts.wizard_state_error;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = error?.message || texts.wizard_error_create_course;
            }
            await Notification.exception(error);
            return null;
        }
    };

    const handleGenerate = async() => {
        const prompt = promptInput ? promptInput.value.trim() : '';
        if (prompt.length < 10) {
            if (promptInput) {
                promptInput.focus();
            }
            return;
        }

        if (btnGenerate) {
            btnGenerate.disabled = true;
            btnGenerate.innerHTML = `
                <svg
                    width="14"
                    height="14"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                    class="spinner"
                >
                    <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                </svg>
                ${texts.wizard_generate_starting}
            `;
        }

        try {
            let systeminstructionid = 0;
            if (state.selectedGuidelineId) {
                const match = state.selectedGuidelineId.match(/^si_(\d+)$/);
                if (match) {
                    systeminstructionid = parseInt(match[1], 10);
                }
            }

            const initResponse = await WizardRepository.initSession({
                prompt,
                lang: state.lang,
                withimages: state.withImages,
                systeminstructionid,
            });

            if (!initResponse.success) {
                throw new Error(initResponse.message || texts.wizard_error_init_session);
            }

            const sessionid = initResponse.sessionid;
            state.sessionid = sessionid;
            state.threadid = initResponse.threadid || '';
            state.streamingurl = initResponse.streamingurl || '';

            if (state.syllabusFilename && state.draftitemid) {
                if (btnGenerate) {
                    btnGenerate.innerHTML = `
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            aria-hidden="true"
                            class="spinner"
                        >
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                        </svg>
                        ${texts.wizard_generate_uploading_syllabus}
                    `;
                }

                const uploadResponse = await WizardRepository.uploadSyllabus(sessionid, state.draftitemid);
                if (!uploadResponse.success) {
                    throw new Error(uploadResponse.message || texts.wizard_error_upload_syllabus);
                }
            }

            stepsUi.transitionToPlanning();
            streamManager.openSSEStream(state.streamingurl);
        } catch (error) {
            window.console.error('Error generating course:', error);
            await Notification.exception(error);
            stepsUi.renderGenerateButtonDefault();
        }
    };

    const sendFeedbackAction = async(action) => {
        if (!state.sessionid) {
            return;
        }

        const instruction = adjustInput ? String(adjustInput.value || '').trim() : '';
        if (action === 'adjust' && !instruction) {
            if (adjustInput) {
                adjustInput.focus();
            }
            return;
        }

        if (btnApprove) {
            btnApprove.disabled = true;
        }
        if (btnAdjust) {
            btnAdjust.disabled = true;
        }
        if (btnAdjustSend) {
            btnAdjustSend.disabled = true;
        }
        if (planActions) {
            planActions.style.display = 'none';
        }
        if (adjustPanel) {
            adjustPanel.style.display = 'none';
        }
        if (planningSpinner) {
            planningSpinner.classList.remove('done');
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = action === 'accept'
                ? texts.wizard_status_approving
                : texts.wizard_status_adjusting;
        }

        try {
            const feedbackPayload = {
                recordid: state.sessionid,
                action,
                instruction,
            };

            // Include selected image IDs when approving detailed plan.
            if (action === 'accept' && state.planningMode === 'detailed') {
                const selectedImageIds = Object.keys(state.selectedDetailedImages)
                    .filter((id) => state.selectedDetailedImages[id] !== false);
                feedbackPayload.selectedimageids = selectedImageIds;

                // PRESERVE detailedTotal BEFORE any state changes or stream opening
                // This value will be used for phase 4 progress tracking
                state.phase4TotalActivities = state.detailedTotal || 0;
                window.console.log('[PHASE4-DEBUG] PRE-FEEDBACK - Preserved phase4TotalActivities:', state.phase4TotalActivities);

                state.completionStats = {
                    units: state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0,
                    activities: state.totalActivities || state.detailedTotal || 0,
                    images: selectedImageIds.length,
                };
            } else if (action === 'accept' && state.currentStage === 'detailed') {
                // PRESERVE detailedTotal BEFORE any state changes or stream opening
                state.phase4TotalActivities = state.detailedTotal || 0;
                window.console.log('[PHASE4-DEBUG] PRE-FEEDBACK - Preserved phase4TotalActivities:', state.phase4TotalActivities);

                state.completionStats = {
                    units: state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0,
                    activities: state.totalActivities || state.detailedTotal || 0,
                    images: Object.keys(state.selectedDetailedImages || {})
                        .filter((id) => state.selectedDetailedImages[id] !== false).length,
                };
            }

            const feedbackResponse = await sendPlanningFeedback(feedbackPayload);

            if (!feedbackResponse || !feedbackResponse.success) {
                throw new Error(feedbackResponse?.message || texts.wizard_error_send_feedback);
            }

            if (action === 'accept') {
                if (state.planningMode === 'detailed') {
                    stepsUi.setStepState('detailed', 'done');
                    stepsUi.setStepState('generating', 'active');
                    state.currentStage = 'generating';

                    window.console.log('[PHASE4-DEBUG] POST-FEEDBACK - Approved detailed plan - initializing phase 4');
                    window.console.log('[PHASE4-DEBUG] POST-FEEDBACK - Setting currentStage to:', state.currentStage);
                    window.console.log('[PHASE4-DEBUG] POST-FEEDBACK - detailedTotal:', state.detailedTotal);
                    window.console.log('[PHASE4-DEBUG] POST-FEEDBACK - phase4TotalActivities:', state.phase4TotalActivities);

                    // Initialize content generation tracking (two-phase hybrid)
                    state.contentGenerationStarted = 0;
                    state.contentGenerationCurrent = 0;

                    window.console.log(
                        '[PHASE4-DEBUG] POST-FEEDBACK - Initialized counters - started:',
                        state.contentGenerationStarted,
                        'current:',
                        state.contentGenerationCurrent
                    );

                    stepsUi.setProgress(0);
                } else {
                    stepsUi.setStepState('planning', 'done');
                    stepsUi.setStepState('detailed', 'active');
                    state.currentStage = 'detailed';
                }
                stepsUi.updateFlowNav();
            }

            window.console.log('[PHASE4-DEBUG] BEFORE-STREAM - phase4TotalActivities:', state.phase4TotalActivities);
            streamManager.openSSEStream(state.streamingurl);
            window.console.log('[PHASE4-DEBUG] AFTER-STREAM - phase4TotalActivities:', state.phase4TotalActivities);
        } catch (error) {
            await Notification.exception(error);
        } finally {
            if (btnApprove) {
                btnApprove.disabled = false;
            }
            if (btnAdjust) {
                btnAdjust.disabled = false;
            }
            if (btnAdjustSend) {
                btnAdjustSend.disabled = false;
            }
        }
    };

    const bindEvents = () => {
        if (promptInput) {
            promptInput.addEventListener('input', updateGenerateButton);
            promptInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleGenerate();
                }
            });
        }

        if (btnGenerate) {
            btnGenerate.addEventListener('click', handleGenerate);
        }

        if (pcToggleBtn) {
            pcToggleBtn.addEventListener('click', () => {
                state.planDetailsOpen = !state.planDetailsOpen;
                if (pcDetailsPanel) {
                    pcDetailsPanel.style.display = state.planDetailsOpen ? 'block' : 'none';
                }
                if (pcChevron) {
                    pcChevron.style.transform = state.planDetailsOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }
            });
        }

        if (btnApprove) {
            btnApprove.addEventListener('click', () => sendFeedbackAction('accept'));
        }
        if (btnAdjust) {
            btnAdjust.addEventListener('click', () => {
                if (adjustPanel) {
                    adjustPanel.style.display = 'block';
                }
                if (adjustInput) {
                    adjustInput.value = '';
                    adjustInput.focus();
                }
            });
        }
        if (btnAdjustCancel) {
            btnAdjustCancel.addEventListener('click', () => {
                if (adjustPanel) {
                    adjustPanel.style.display = 'none';
                }
            });
        }
        if (btnAdjustSend) {
            btnAdjustSend.addEventListener('click', () => sendFeedbackAction('adjust'));
        }

        if (btnBackFlow) {
            btnBackFlow.addEventListener('click', () => {
                if (state.currentStage === 'planning') {
                    stepsUi.backToContext();
                    return;
                }
                if (state.currentStage === 'detailed') {
                    stepsUi.setStepState('planning', 'active');
                    stepsUi.setStepState('detailed', 'pending');
                    stepsUi.setStepState('generating', 'pending');
                    state.currentStage = 'planning';
                    stepsUi.switchPlanMode('sections');
                    planningUi.showReviewActions('initial');
                    stepsUi.updateFlowNav();
                }
            });
        }

        if (btnCancelFlow) {
            btnCancelFlow.addEventListener('click', () => {
                stepsUi.backToContext();
            });
        }

        if (btnOpenMoodleCourse) {
            btnOpenMoodleCourse.addEventListener('click', () => {
                if (!state.createdCourseUrl) {
                    return;
                }
                window.open(state.createdCourseUrl, '_blank', 'noopener,noreferrer');
            });
        }

        if (btnCreateAnotherCourse) {
            btnCreateAnotherCourse.addEventListener('click', () => {
                resetForAnotherCourse();
            });
        }

        window.clearSyllabus = () => {
            state.syllabusFile = null;
            state.syllabusFilename = null;
            state.draftitemid = null;
            const chipSyllabus = document.getElementById('chipSyllabus');
            if (chipSyllabus) {
                chipSyllabus.classList.add('hidden');
            }
            refreshChipsRow();
        };

        window.clearGuideline = () => {
            state.selectedGuidelineId = null;
            refreshGuidelineChip();
        };
    };

    return {
        createCourseFromSession,
        handleGenerate,
        sendFeedbackAction,
        bindEvents,
    };
};
