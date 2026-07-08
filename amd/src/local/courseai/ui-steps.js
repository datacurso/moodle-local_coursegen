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
 * Stepper and navigation UI helpers — orchestrator.
 *
 * @module     local_coursegen/local/courseai/ui-steps
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { setCompactChatState } from './ui-planning';
import { resetPlanningState as doReset } from './steps/reset';

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
        clearLog,
    } = deps;

    const {
        contextView,
        courseaiWorkspace,
        planningView,
        btnGenerate,
        pcPct,
        pcBarFill,
        planSectionsView,
        planDetailedView,
        planMarkdownView,
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
        doReset(options, {state, elements, texts, setProgress});
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
        // Wipe the thread feed too: leaving a session back to the form must
        // not leak its turns into the next session's transcript (the abandoned
        // session keeps its full history server-side and replays on resume).
        if (typeof clearLog === 'function') {
            clearLog();
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
