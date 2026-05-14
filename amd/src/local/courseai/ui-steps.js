// This file is part of Moodle - http://moodle.org/

/**
 * Stepper and navigation UI helpers.
 *
 * @module     local_coursegen/local/courseai/ui-steps
 */

import { setCompactChatState } from './ui-planning';

/**
 * Create step UI helpers.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createStepsUi = (deps) => {
    const {
        state,
        elements,
        generateButtonHtml,
        texts,
    } = deps;

    const {
        contextView,
        courseaiWorkspace,
        planningView,
        planningProgressCard,
        btnGenerate,
        completionView,
        completionSummary,
        planSectionsView,
        planDetailedView,
        planMarkdownView,
        planMarkdown,
        planSectionsList,
        planDetailedList,
        prvSections,
        planReviewCard,
        planActions,
        pcDetailsPanel,
        pcToggleRow,
        pcChevron,
        prvLiveNote,
        typingCursor,
        planningSpinner,
        planningCheckIcon,
        pcIconWrap,
        prvHeader,
        prvHeaderTitle,
        prvHeaderSub,
        prvSpinnerIcon,
        prvCheckIcon,
        pcStep,
        pcTitle,
        pcSubtitle,
        pcPct,
        pcBarFill,
    } = elements;

    let closeStreamFn = () => {};

    const bindCloseStream = (fn) => {
        closeStreamFn = fn;
    };

    const setProgress = (pct) => {
        const clamped = Math.min(100, Math.max(0, Math.round(pct)));

        window.console.log('[SETPROGRESS-DEBUG] Called with:', pct, 'clamped to:', clamped);
        window.console.log('[SETPROGRESS-DEBUG] pcPct element exists:', !!pcPct);
        window.console.log('[SETPROGRESS-DEBUG] pcBarFill element exists:', !!pcBarFill);

        if (pcPct) {
            pcPct.textContent = `${clamped}${texts.courseai_progress_percent}`;
            window.console.log('[SETPROGRESS-DEBUG] Updated pcPct to:', pcPct.textContent);
        } else {
            window.console.warn('[SETPROGRESS-DEBUG] pcPct element is NULL!');
        }
        if (pcBarFill) {
            pcBarFill.style.width = `${clamped}%`;
            window.console.log('[SETPROGRESS-DEBUG] Updated pcBarFill width to:', pcBarFill.style.width);
        } else {
            window.console.warn('[SETPROGRESS-DEBUG] pcBarFill element is NULL!');
        }
    };

    const setStepState = (step, stateName) => {
        const stepEl = document.querySelector(`[data-step="${step}"]`);
        if (!stepEl) {
            return;
        }
        stepEl.classList.remove('active', 'pending', 'done');
        stepEl.classList.add(stateName);
    };

    const renderGenerateButtonDefault = () => {
        if (!btnGenerate) {
            return;
        }
        btnGenerate.disabled = false;
        btnGenerate.innerHTML = generateButtonHtml;
    };

    const updateFlowNav = () => {
        // Navigation removed - courseai is now forward-only
    };

    const switchPlanMode = (mode) => {
        if (state.planningMode === mode) {
            return;
        }
        state.planningMode = mode;
        if (planSectionsView) {
            planSectionsView.style.display = mode === 'sections' ? 'block' : 'none';
        }
        if (planDetailedView) {
            planDetailedView.style.display = mode === 'detailed' ? 'block' : 'none';
        }
        if (planMarkdownView) {
            planMarkdownView.style.display = mode === 'markdown' ? 'block' : 'none';
        }
    };

    const resetPlanningState = () => {
        state.planBuffer = '';
        state.planningMode = null;
        state.planDetailsOpen = false;
        state.totalSections = 0;
        state.totalActivities = 0;

        if (planMarkdown) {
            planMarkdown.innerHTML = '';
        }
        if (planSectionsList) {
            planSectionsList.innerHTML = '';
        }
        if (planDetailedList) {
            planDetailedList.innerHTML = '';
        }
        if (prvSections) {
            prvSections.innerHTML = '';
        }
        if (planSectionsView) {
            planSectionsView.style.display = 'none';
        }
        if (planDetailedView) {
            planDetailedView.style.display = 'none';
        }
        if (planMarkdownView) {
            planMarkdownView.style.display = 'none';
        }
        if (planReviewCard) {
            planReviewCard.style.display = 'none';
        }
        if (completionView) {
            completionView.style.display = 'none';
        }
        if (completionSummary) {
            completionSummary.textContent = texts.courseai_completion_summary_default;
        }
        if (planningProgressCard) {
            planningProgressCard.style.display = '';
        }
        if (planActions) {
            planActions.style.display = 'none';
        }
        if (pcDetailsPanel) {
            pcDetailsPanel.style.display = 'none';
        }
        if (pcToggleRow) {
            pcToggleRow.style.display = 'none';
        }
        if (pcChevron) {
            pcChevron.style.transform = 'rotate(0deg)';
        }
        if (prvLiveNote) {
            prvLiveNote.style.display = 'none';
            prvLiveNote.textContent = '';
        }
        if (typingCursor) {
            typingCursor.classList.remove('hidden');
        }
        if (planningSpinner) {
            planningSpinner.classList.remove('done');
        }
        if (prvHeader) {
            prvHeader.classList.remove('prv-header--done');
            prvHeader.classList.add('prv-header--stream');
        }
        if (prvHeaderTitle) {
            prvHeaderTitle.textContent = texts.courseai_state_structuring;
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = texts.courseai_state_starting;
        }
        if (planningSpinner) {
            planningSpinner.style.display = '';
        }
        if (planningCheckIcon) {
            planningCheckIcon.style.display = 'none';
        }
        if (pcIconWrap) {
            pcIconWrap.style.background = '';
            pcIconWrap.style.color = '';
        }
        if (prvSpinnerIcon) {
            prvSpinnerIcon.style.display = '';
        }
        if (prvCheckIcon) {
            prvCheckIcon.style.display = 'none';
        }
        if (pcStep) {
            pcStep.textContent = texts.courseai_state_planning;
        }
        if (pcTitle) {
            pcTitle.textContent = texts.courseai_state_structuring;
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = '';
        }
        setProgress(0);

        state.detailedTotal = 0;
        state.detailedCurrent = 0;
        state.phase4TotalActivities = 0;
        state.contentGenerationStarted = 0;
        state.contentGenerationCurrent = 0;
        state.planSectionsData = [];
        state.detailedTotal = 0;
        state.detailedCurrent = 0;
        state.phase4TotalActivities = 0;
        state.contentGenerationStarted = 0;
        state.contentGenerationCurrent = 0;
        state.planSectionsData = [];
        state.detailedActivityEls = {};
        state.detailedSectionMeta = {};
        state.selectedDetailedImages = {};
        state.completionStats = null;
        state.createdCourseUrl = '';
        state.createdCourseResult = null;
        // NOTE: compact chat lifecycle is NOT reset here intentionally.
        // openSSEStream() calls resetPlanningState() at stream start, and the chat
        // must remain visible (disabled) during streaming. Chat reset only happens
        // in backToContext() which is a true full-navigation reset.
    };

    const backToContext = () => {
        closeStreamFn();
        state.currentStage = 'planning';
        if (planningView) {
            planningView.style.display = 'none';
        }
        if (courseaiWorkspace) {
            courseaiWorkspace.classList.remove('is-planning');
        }
        if (contextView) {
            contextView.style.display = '';
        }
        setStepState('context', 'active');
        setStepState('planning', 'pending');
        setStepState('generating', 'pending');
        // Reset compact chat before other state (it depends on some state)
        setCompactChatState(deps, 'reset');
        resetPlanningState();
        renderGenerateButtonDefault();
        updateFlowNav();

        // Clear both the compact chat input and the main prompt input.
        // The compact chat syncs keystrokes to the main prompt, so without this
        // the user would see their last adjustment text when landing back on phase 1.
        if (elements.compactPromptInput) {
            elements.compactPromptInput.value = '';
        }
        if (elements.promptInput) {
            elements.promptInput.value = '';
        }
    };

    const transitionToPlanning = () => {
        setStepState('context', 'done');
        setStepState('planning', 'active');
        state.currentStage = 'planning';

        // Keep context view visible on the left; progress/streaming stays on the right.
        if (contextView) {
            contextView.style.display = '';
        }
        if (courseaiWorkspace) {
            courseaiWorkspace.classList.add('is-planning');
        }

        if (planningView) {
            planningView.style.display = 'flex';
        }

        // Show compact chat in disabled state immediately when phase 2 starts
        // This ensures the chat is visible but non-interactive during streaming
        setCompactChatState(deps, 'disabled');

        updateFlowNav();
    };

    return {
        bindCloseStream,
        setProgress,
        setStepState,
        renderGenerateButtonDefault,
        updateFlowNav,
        switchPlanMode,
        resetPlanningState,
        backToContext,
        transitionToPlanning,
    };
};
