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
 * Stepper and navigation UI helpers.
 *
 * @module     local_coursegen/local/courseai/ui-steps
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

        if (pcPct) {
            pcPct.textContent = `${clamped}${texts.courseai_progress_percent}`;
        }
        if (pcBarFill) {
            pcBarFill.style.width = `${clamped}%`;
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

    const resetPlanningState = (options = {}) => {
        const showLoading = options.showLoading !== false;
        state.planBuffer = '';
        state.planningMode = null;
        state.planDetailsOpen = false;
        state.totalSections = 0;
        state.totalActivities = 0;

        // Reset loading/stream content visibility
        const loadingEl = document.getElementById('planningLoading');
        const streamContentEl = document.getElementById('planningStreamContent');
        if (loadingEl) {
            loadingEl.style.display = showLoading ? '' : 'none';
        }
        if (streamContentEl) {
            streamContentEl.style.display = showLoading ? 'none' : '';
        }

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
            planningProgressCard.style.display = showLoading ? 'none' : '';
        }
        if (planActions) {
            planActions.style.display = 'none';
        }
        if (pcDetailsPanel) {
            pcDetailsPanel.style.display = 'none';
            pcDetailsPanel.innerHTML = '';
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
        state.latestInitialSections = [];
        state.detailedTotal = 0;
        state.detailedCurrent = 0;
        state.phase4TotalActivities = 0;
        state.contentGenerationStarted = 0;
        state.contentGenerationCurrent = 0;
        state.planSectionsData = [];
        state.latestInitialSections = [];
        state.generationTracker = null;
        state.structuredActivityProgress = false;
        state.activityProgressTotal = 0;
        state.activityProgressStarted = 0;
        state.activityProgressDone = 0;
        state.imageProgressDone = 0;
        state.imageProgressTotal = 0;
        state.detailedActivityEls = {};
        state.detailedSectionMeta = {};
        state.selectedDetailedImages = {};
        state.courseTitle = '';
        state.activitiesPlannedCount = 0;
        state.completionStats = null;
        state.createdCourseUrl = '';
        state.createdCourseResult = null;
        state.generationRound = (state.generationRound || 0) + 1;

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
        state.initialPrompt = '';
        state.generationRound = 0;
        if (elements.initialPromptText) {
            elements.initialPromptText.textContent = '';
        }
        if (elements.initialPromptHistory) {
            elements.initialPromptHistory.classList.add('hidden');
        }
        // Full reset: clear checklist and adjustment history from DOM
        if (elements.checklist) {
            elements.checklist.classList.add('hidden');
        }
        if (elements.checklistList) {
            elements.checklistList.innerHTML = '';
        }
        if (elements.adjustmentHistory) {
            elements.adjustmentHistory.classList.add('hidden');
            elements.adjustmentHistory.innerHTML = '';
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
