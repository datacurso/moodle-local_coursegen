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
 * Course AI action handlers — orchestrator.
 *
 * Assembles the public action API from focused submodules and wires DOM events.
 *
 * @module     local_coursegen/local/courseai/actions
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { setCompactChatState } from './ui-planning';
import FormAutocomplete from 'core/form-autocomplete';
import { buildCompletionSummary } from './actions/summary';
import { showCourseReviewPanel, createCourseFromSession } from './actions/course-create';
import { sendFeedbackAction } from './actions/feedback';
import { handleGenerate } from './actions/generate';

/**
 * Create courseai actions and event bindings.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createCourseaiActions = (deps) => {
    const {
        state, elements, Notification, CourseaiRepository,
        sendPlanningFeedback, createCourse, getCourseSettings,
        updateGenerateButton, refreshChipsRow, refreshGuidelineChip,
        stepsUi, planningUi, streamManager, texts, formatTemplate, emitLog,
    } = deps;

    const {
        promptInput, btnGenerate, btnApprove, planActions,
        pcToggleBtn, pcDetailsPanel, pcChevron,
        planningProgressCard, completionView, completionSummary,
        btnOpenMoodleCourse, btnCreateAnotherCourse,
        btnWithImages, imgToggleWrap, langSelect,
        compactPromptInput, btnCompactRegenerate,
        initialPromptHistory, initialPromptText,
    } = elements;

    const renderInitialPromptHistory = (message) => {
        if (initialPromptText) {
            initialPromptText.textContent = message || '';
        }
        if (initialPromptHistory) {
            initialPromptHistory.classList.toggle('hidden', !message);
        }
    };

    const showCompletionView = (result) => {
        state.createdCourseResult = result || null;
        state.createdCourseUrl = result?.courseurl || '';
        state.currentStage = 'completed';
        // The course is created and can no longer be edited from this wizard, so the
        // composer must be gone on the success view. Hide it explicitly here (not only
        // via the body-class CSS) so a stale/aggregated stylesheet can't leave it
        // visible, and keep the declarative class in sync.
        state.planApproved = true;
        document.body.classList.add('cg-plan-approved');
        if (elements.compactChatCard) {
            elements.compactChatCard.style.display = 'none';
        }
        if (completionSummary) {
            completionSummary.textContent = buildCompletionSummary(state, texts, formatTemplate);
        }
        if (planningProgressCard) { planningProgressCard.style.display = 'none'; }
        if (elements.planReviewCard) { elements.planReviewCard.style.display = 'none'; }
        if (planActions) { planActions.style.display = 'none'; }
        if (completionView) { completionView.style.display = 'flex'; }
        if (btnOpenMoodleCourse) { btnOpenMoodleCourse.disabled = !state.createdCourseUrl; }
        stepsUi.setStepState('planning', 'done');
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
        if (promptInput) { promptInput.value = ''; }
        if (langSelect) { langSelect.value = state.lang; }
        if (btnWithImages) { btnWithImages.checked = false; }
        if (imgToggleWrap) { imgToggleWrap.classList.remove('on'); }
        const chipSyllabus = document.getElementById('chipSyllabus');
        if (chipSyllabus) { chipSyllabus.classList.add('hidden'); }
        const chipSyllabusName = document.getElementById('chipSyllabusName');
        if (chipSyllabusName) { chipSyllabusName.textContent = ''; }
        refreshGuidelineChip();
        refreshChipsRow();
        renderInitialPromptHistory('');
        stepsUi.backToContext();
        updateGenerateButton();
    };

    /** Shared generate context built once and reused in multiple event listeners. */
    const genCtx = () => ({
        state, elements, texts,
        CourseaiRepository, stepsUi, planningUi, streamManager,
        Notification, renderInitialPromptHistory, emitLog,
    });

    /** Shared feedback context. */
    const fbCtx = () => ({
        state, elements, texts, Notification,
        setCompactChatState, deps,
        sendPlanningFeedback, streamManager,
        stepsUi, planningUi, emitLog,
    });

    const bindEvents = () => {
        if (promptInput) {
            promptInput.addEventListener('input', updateGenerateButton);
            promptInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleGenerate(genCtx());
                }
            });
        }
        if (btnGenerate) {
            btnGenerate.addEventListener('click', () => handleGenerate(genCtx()));
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
            btnApprove.addEventListener('click', () => sendFeedbackAction('accept', fbCtx()));
        }
        if (btnCompactRegenerate) {
            btnCompactRegenerate.addEventListener('click', () => {
                if (state.isStreaming) { return; }
                const instruction = compactPromptInput ? compactPromptInput.value.trim() : '';
                if (instruction.length < 10) {
                    if (compactPromptInput) { compactPromptInput.focus(); }
                    return;
                }
                sendFeedbackAction('adjust', fbCtx());
            });
        }
        if (compactPromptInput && promptInput) {
            compactPromptInput.addEventListener('input', () => {
                promptInput.value = compactPromptInput.value;
            });
        }
        const btnWizardCancel = document.getElementById('btnWizardCancel');
        if (btnWizardCancel) {
            btnWizardCancel.addEventListener('click', () => stepsUi.backToContext());
        }
        if (btnOpenMoodleCourse) {
            btnOpenMoodleCourse.addEventListener('click', () => {
                if (!state.createdCourseUrl) { return; }
                window.open(state.createdCourseUrl, '_blank', 'noopener,noreferrer');
            });
        }
        if (btnCreateAnotherCourse) {
            btnCreateAnotherCourse.addEventListener('click', () => {
                window.location.href = 'aicoursecreation.php';
            });
        }
        window.clearSyllabus = () => {
            state.syllabusFile = null;
            state.syllabusFilename = null;
            state.draftitemid = null;
            const chipSyllabus = document.getElementById('chipSyllabus');
            if (chipSyllabus) { chipSyllabus.classList.add('hidden'); }
            refreshChipsRow();
            const compactChipSyllabus = document.getElementById('compactChipSyllabus');
            if (compactChipSyllabus) { compactChipSyllabus.classList.add('hidden'); }
            const compactChipsRow = document.getElementById('compactChipsRow');
            const compactChipGuideline = document.getElementById('compactChipGuideline');
            if (compactChipsRow) {
                const hasGuideline = compactChipGuideline && !compactChipGuideline.classList.contains('hidden');
                compactChipsRow.style.display = hasGuideline ? 'flex' : 'none';
            }
        };
        window.clearGuideline = () => {
            state.selectedGuidelineId = null;
            refreshGuidelineChip();
        };
    };

    return {
        showCompletionView,
        showCourseReviewPanel: () => showCourseReviewPanel(
            state, elements, texts, getCourseSettings, FormAutocomplete
        ),
        createCourseFromSession: (overrides = null) => createCourseFromSession(
            state, elements, texts, stepsUi, Notification, createCourse, showCompletionView, overrides
        ),
        handleGenerate: () => handleGenerate(genCtx()),
        sendFeedbackAction: (action) => sendFeedbackAction(action, fbCtx()),
        resetForAnotherCourse,
        bindEvents,
    };
};
