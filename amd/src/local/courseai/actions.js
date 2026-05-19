// This file is part of Moodle - http://moodle.org/

/**
 * Courseai action handlers.
 *
 * @module     local_coursegen/local/courseai/actions
 */

import { setCompactChatState } from './ui-planning';

/**
 * Create courseai actions and event bindings.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createCourseaiActions = (deps) => {
    const {
        state,
        elements,
        Notification,
        CourseaiRepository,
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
        btnApprove,
        planActions,
        planningSpinner,
        pcSubtitle,
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
        compactPromptInput,
        btnCompactRegenerate,
        initialPromptHistory,
        initialPromptText,
        adjustmentHistory,
    } = elements;

    const renderInitialPromptHistory = (message) => {
        if (initialPromptText) {
            initialPromptText.textContent = message || '';
        }
        if (initialPromptHistory) {
            initialPromptHistory.classList.toggle('hidden', !message);
        }
    };

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
            return formatTemplate(texts.courseai_completion_summary_with_images, {
                units,
                activities,
                images,
            });
        }

        return formatTemplate(texts.courseai_completion_summary_no_images, {
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
        state.initialPrompt = '';

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
        renderInitialPromptHistory('');
        stepsUi.backToContext();
        updateGenerateButton();
    };

    const createCourseFromSession = async() => {
        if (!state.sessionid) {
            return;
        }

        const CREATE_COURSE_TIMEOUT_MS = 180000;
        let progressInterval = null;

        try {
            if (elements.pcStep) {
                elements.pcStep.textContent = texts.courseai_state_completed;
            }
            if (elements.pcTitle) {
                elements.pcTitle.textContent = texts.courseai_course_creating;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.courseai_course_creating_subtitle;
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

            const createRequest = createCourse({recordid: state.sessionid});
            const timeoutPromise = new Promise((_, reject) => {
                setTimeout(() => {
                    reject(new Error(texts.courseai_error_connection));
                }, CREATE_COURSE_TIMEOUT_MS);
            });
            const result = await Promise.race([createRequest, timeoutPromise]);

            // Stop simulation and jump to 100%
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            stepsUi.setProgress(100);

            if (!result || !result.success) {
                throw new Error(result?.message || texts.courseai_error_create_course);
            }

            showCompletionView(result);
            return result;
        } catch (error) {
            if (progressInterval) {
                clearInterval(progressInterval);
            }

            if (elements.pcStep) {
                elements.pcStep.textContent = texts.courseai_state_error;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = error?.message || texts.courseai_error_create_course;
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

        state.initialPrompt = prompt;
        renderInitialPromptHistory(prompt);

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
                ${texts.courseai_generate_starting}
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

            const initResponse = await CourseaiRepository.initSession({
                prompt,
                lang: state.lang,
                withimages: state.withImages,
                systeminstructionid,
            });

            if (!initResponse.success) {
                throw new Error(initResponse.message || texts.courseai_error_init_session);
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
                        ${texts.courseai_generate_uploading_syllabus}
                    `;
                }

                const uploadResponse = await CourseaiRepository.uploadSyllabus(sessionid, state.draftitemid);
                if (!uploadResponse.success) {
                    throw new Error(uploadResponse.message || texts.courseai_error_upload_syllabus);
                }
            }

            stepsUi.transitionToPlanning();
            // Sync chips (syllabus, guideline, images, lang) to the compact chat immediately
            // so they are visible from the moment phase 2 streaming begins.
            planningUi.syncCompactChatState();
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

        if (btnApprove) {
            btnApprove.disabled = true;
        }
        if (planActions) {
            planActions.style.display = 'none';
        }
        // Disable controls and Regenerar button during stream
        setCompactChatState(deps, 'disabled');
        /* PAUSAR — to be implemented later
        if (action === 'adjust' && btnCompactRegenerate) {
            state.isStreaming = true;
            const pauseIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" ' +
                'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
                'stroke-linejoin="round" aria-hidden="true">' +
                '<rect x="6" y="4" width="4" height="16"/>' +
                '<rect x="14" y="4" width="4" height="16"/></svg>';
            btnCompactRegenerate.innerHTML = `${pauseIcon} ${texts.courseai_btn_pause || 'Pausar'}`;
            btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_pause || 'Pausar');
            btnCompactRegenerate.setAttribute('title', texts.courseai_btn_pause || 'Pausar');
            btnCompactRegenerate.disabled = false;
        }
        */
        if (planningSpinner) {
            planningSpinner.classList.remove('done');
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = action === 'accept'
                ? texts.courseai_status_approving
                : texts.courseai_status_adjusting;
        }

        try {
            const instruction = action === 'adjust' && compactPromptInput
                ? compactPromptInput.value.trim()
                : '';

            // Show adjustment as a chat message paired with a response slot
            if (action === 'adjust' && instruction && adjustmentHistory) {
                const round = (state.generationRound || 0) + 1;
                const roundContainer = document.createElement('div');
                roundContainer.className = 'courseai-round';
                roundContainer.setAttribute('data-round', round);

                const msgEl = document.createElement('div');
                msgEl.className = 'courseai-chat-history';

                const messageBubble = document.createElement('div');
                messageBubble.className = 'courseai-chat-message courseai-chat-message--user';

                const messageText = document.createElement('p');
                messageText.textContent = instruction;

                messageBubble.appendChild(messageText);
                msgEl.appendChild(messageBubble);

                const responseSlot = document.createElement('div');
                responseSlot.className = 'courseai-round-response';
                responseSlot.setAttribute('data-round', round);

                roundContainer.appendChild(msgEl);
                roundContainer.appendChild(responseSlot);
                adjustmentHistory.appendChild(roundContainer);
                adjustmentHistory.classList.remove('hidden');
                if (compactPromptInput) {
                    compactPromptInput.value = '';
                }
            }

            const feedbackPayload = {
                recordid: state.sessionid,
                action,
                instruction,
                withimages: state.withImages,
            };

            // Include selected image IDs when approving the plan.
            if (action === 'accept') {
                const selectedImageIds = Object.keys(state.selectedDetailedImages)
                    .filter((id) => state.selectedDetailedImages[id] !== false);
                feedbackPayload.selectedimageids = selectedImageIds;

                // PRESERVE detailedTotal BEFORE any state changes or stream opening
                state.phase4TotalActivities = state.detailedTotal || 0;

                state.completionStats = {
                    units: state.totalSections || Object.keys(state.detailedSectionMeta || {}).length || 0,
                    activities: state.totalActivities || state.detailedTotal || 0,
                    images: selectedImageIds.length,
                };
            }

            const feedbackResponse = await sendPlanningFeedback(feedbackPayload);

            if (!feedbackResponse || !feedbackResponse.success) {
                throw new Error(feedbackResponse?.message || texts.courseai_error_send_feedback);
            }

            if (action === 'accept') {
                stepsUi.setStepState('planning', 'done');
                stepsUi.setStepState('generating', 'active');
                state.currentStage = 'generating';

                // Initialize content generation tracking
                state.contentGenerationStarted = 0;
                state.contentGenerationCurrent = 0;

                stepsUi.setProgress(0);
                stepsUi.updateFlowNav();
            }

            // Sync chips to compact chat so they remain visible during phase 3 streaming.
            // For 'adjust' actions the text is kept so the user sees what they submitted.
            if (action === 'accept') {
                planningUi.syncCompactChatState();
            }

            window.console.log('[PHASE4-DEBUG] BEFORE-STREAM - phase4TotalActivities:', state.phase4TotalActivities);
            const streamMode = action === 'accept' ? 'generating' : 'planning';
            streamManager.openSSEStream(state.streamingurl, 0, streamMode);
            window.console.log('[PHASE4-DEBUG] AFTER-STREAM - phase4TotalActivities:', state.phase4TotalActivities);
        } catch (error) {
            await Notification.exception(error);
        } finally {
            if (btnApprove) {
                btnApprove.disabled = false;
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
                pcToggleBtn.setAttribute('aria-expanded', state.planDetailsOpen ? 'true' : 'false');
                if (pcChevron) {
                    pcChevron.style.transform = state.planDetailsOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }
            });
        }

        if (btnApprove) {
            btnApprove.addEventListener('click', () => sendFeedbackAction('accept'));
        }

        // Compact chat regeneration / pause
        if (btnCompactRegenerate) {
            btnCompactRegenerate.addEventListener('click', () => {
                // While streaming this control must remain non-interactive.
                if (state.isStreaming) {
                    return;
                }

                // Otherwise, regenerate
                const instruction = compactPromptInput ? compactPromptInput.value.trim() : '';
                if (instruction.length < 10) {
                    if (compactPromptInput) {
                        compactPromptInput.focus();
                    }
                    return;
                }
                sendFeedbackAction('adjust');
            });
        }

        // Sync compact chat input with main prompt input
        if (compactPromptInput && promptInput) {
            compactPromptInput.addEventListener('input', () => {
                promptInput.value = compactPromptInput.value;
            });
        }

        // Wizard cancel button - return to phase 1
        const btnWizardCancel = document.getElementById('btnWizardCancel');
        if (btnWizardCancel) {
            btnWizardCancel.addEventListener('click', () => {
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
            // Hide main chip
            const chipSyllabus = document.getElementById('chipSyllabus');
            if (chipSyllabus) {
                chipSyllabus.classList.add('hidden');
            }
            refreshChipsRow();
            // Also hide compact chip
            const compactChipSyllabus = document.getElementById('compactChipSyllabus');
            if (compactChipSyllabus) {
                compactChipSyllabus.classList.add('hidden');
            }
            const compactChipsRow = document.getElementById('compactChipsRow');
            const compactChipGuideline = document.getElementById('compactChipGuideline');
            if (compactChipsRow) {
                const hasGuideline = compactChipGuideline && !compactChipGuideline.classList.contains('hidden');
                compactChipsRow.style.display = hasGuideline ? 'flex' : 'none';
            }
        };

        window.clearGuideline = () => {
            state.selectedGuidelineId = null;
            // refreshGuidelineChip already updates both main and compact chips
            refreshGuidelineChip();
        };
    };

    return {
        createCourseFromSession,
        handleGenerate,
        sendFeedbackAction,
        resetForAnotherCourse,
        bindEvents,
    };
};
