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
        planningProgressCard, completionView, completionTitle, completionSummary,
        btnOpenMoodleCourse, btnOpenMoodleCourseLabel, btnCreateAnotherCourse,
        importProgressView, importProgressSpinner, importProgressFailedIcon,
        importProgressTitle, importProgressSummary, btnOpenGeneratedCourse,
        btnWithImages, imgToggleWrap, langSelect,
        btnWithSubsections, subToggleWrap,
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

    /**
     * Fire a celebratory confetti burst from the completion badge: colourful pieces
     * shoot outward from the cone and arc down under gravity, spinning and fading.
     * Uses the Web Animations API (no external assets). Skipped under reduced motion.
     *
     * @param {HTMLElement} container - The .pc-confetti layer inside the badge.
     * @returns {void}
     */
    const fireConfetti = (container) => {
        if (!container) {
            return;
        }
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        const colors = ['#ED6E54', '#F1AA1E', '#5B4590', '#3B9EE5', '#E5528A', '#3FBF6F'];
        const rand = (min, max) => min + Math.random() * (max - min);
        const count = 40;
        for (let i = 0; i < count; i++) {
            const piece = document.createElement('i');
            const circle = i % 3 === 0;
            const w = circle ? rand(5, 8) : rand(4, 7);
            const h = circle ? w : rand(8, 14);
            piece.style.cssText = 'position:absolute;top:42%;left:50%;'
                + 'width:' + w.toFixed(1) + 'px;height:' + h.toFixed(1) + 'px;'
                + 'background:' + colors[i % colors.length] + ';'
                + 'border-radius:' + (circle ? '50%' : '1px') + ';will-change:transform,opacity;';
            container.appendChild(piece);
            // Burst mostly upward/outward (a popper fires up), then gravity pulls it down.
            // Long throws so the confetti reaches far across the view.
            const angle = rand(-178, -2) * Math.PI / 180;
            const dist = rand(150, 360);
            const bx = Math.cos(angle) * dist;
            const by = Math.sin(angle) * dist;
            const fallY = by + rand(230, 520);
            const drift = bx + rand(-40, 40);
            const spin = (i % 2 ? 1 : -1) * rand(240, 620);
            const midX = bx + (drift - bx) * 0.5;
            const midY = by + (fallY - by) * 0.5;
            const peak = 'translate(calc(-50% + ' + bx.toFixed(1) + 'px), calc(-50% + '
                + by.toFixed(1) + 'px)) scale(1) rotate(' + (spin * 0.35).toFixed(0) + 'deg)';
            const mid = 'translate(calc(-50% + ' + midX.toFixed(1) + 'px), calc(-50% + '
                + midY.toFixed(1) + 'px)) scale(1) rotate(' + (spin * 0.7).toFixed(0) + 'deg)';
            const end = 'translate(calc(-50% + ' + drift.toFixed(1) + 'px), calc(-50% + '
                + fallY.toFixed(1) + 'px)) scale(.9) rotate(' + spin.toFixed(0) + 'deg)';
            // Quick burst out (to ~0.28), then a SLOW gravity fall + late fade so the
            // tail lingers — the last third of the (longer) duration is the settle.
            const anim = piece.animate([
                {transform: 'translate(-50%,-50%) scale(.4) rotate(0deg)', opacity: 0, offset: 0},
                {opacity: 1, offset: 0.1},
                {transform: peak, opacity: 1, offset: 0.28},
                {transform: mid, opacity: 1, offset: 0.68},
                {transform: end, opacity: 0, offset: 1}
            ], {duration: rand(1700, 2700), easing: 'cubic-bezier(.1,.55,.25,1)', fill: 'forwards'});
            // Clean each piece up when it finishes (both bursts leave no DOM behind).
            anim.onfinish = () => piece.remove();
        }
    };

    /**
     * Close the generation layout: the course exists (or is being imported) and can no
     * longer be edited from this wizard, so the composer and the plan cards go away and
     * only the final card (completion or import progress) stays.
     *
     * @returns {void}
     */
    const finishGenerationLayout = () => {
        // Hide the composer explicitly here (not only via the body-class CSS) so a
        // stale/aggregated stylesheet can't leave it visible, and keep the declarative
        // class in sync.
        state.planApproved = true;
        document.body.classList.add('cg-plan-approved');
        if (elements.compactChatCard) {
            elements.compactChatCard.style.display = 'none';
        }
        if (planningProgressCard) { planningProgressCard.style.display = 'none'; }
        if (elements.planReviewCard) { elements.planReviewCard.style.display = 'none'; }
        if (planActions) { planActions.style.display = 'none'; }
        // Generation is over: drop the live progress view (the plan cards) so only the
        // final panel shows, and clear the generation styling.
        document.body.classList.remove('cg-generating');
        if (elements.planDetailedView) { elements.planDetailedView.style.display = 'none'; }
        stepsUi.setStepState('planning', 'done');
        stepsUi.setStepState('generating', 'done');
        stepsUi.updateFlowNav();
        // Drop any lingering "working" indicator (e.g. the one shown when the user hit
        // Accept). Generation is over, so it must not sit in the bottom slot beside the
        // final view.
        const workingEntry = document.getElementById('cgFeedbackThinking');
        if (workingEntry) { workingEntry.remove(); }
        // Pin the left thread to the bottom so the final turn is comfortably visible.
        // Without this the newest turns sit below the fold and the user has to scroll
        // down to see the completion message. Deferred to the next frame so it runs
        // after this view's layout changes settle.
        const chatScroll = document.getElementById('courseaiChatScroll');
        if (chatScroll) {
            window.requestAnimationFrame(() => {
                chatScroll.scrollTop = chatScroll.scrollHeight;
            });
        }
    };

    const showCompletionView = (result) => {
        stopImportPolling();
        state.createdCourseResult = result || null;
        state.createdCourseUrl = result?.courseurl || '';
        state.currentStage = 'completed';
        const importCompleted = Boolean(result?.importcompleted);
        finishGenerationLayout();
        if (importProgressView) { importProgressView.style.display = 'none'; }
        if (completionTitle) {
            completionTitle.textContent = importCompleted
                ? texts.courseai_import_completed_title
                : texts.courseai_completion_title;
        }
        if (completionSummary) {
            completionSummary.textContent = importCompleted
                ? texts.import_completed
                : buildCompletionSummary(state, texts, formatTemplate);
        }
        if (btnOpenMoodleCourseLabel) {
            btnOpenMoodleCourseLabel.textContent = importCompleted
                ? texts.courseai_import_btn_open_imported
                : texts.courseai_completion_btn_open_course;
        }
        if (completionView) { completionView.style.display = 'flex'; }
        if (btnOpenMoodleCourse) { btnOpenMoodleCourse.disabled = !state.createdCourseUrl; }
        // Celebratory confetti — a DOUBLE pop. Both bursts run the SAME animation; the
        // second fires ~1.5s later: late enough to read as a distinct second pop, but
        // while the first is still falling so there is NO empty gap between them (2s
        // left a dead pause; 1.2s felt almost simultaneous).
        const confettiLayer = document.getElementById('pcConfetti');
        fireConfetti(confettiLayer);
        window.setTimeout(() => fireConfetti(confettiLayer), 1500);
    };

    // ---- Import into an existing course (ticket #9539) -------------------------------
    // The backend answers create_course with importqueued=true and the STAGING course.
    // In the background execution an adhoc task copies the target and imports the
    // staging into the copy, and the page polls the session state until importstate
    // is done|failed (capped). In the foreground execution the answer already carries
    // the terminal importstate (and copycourseid), so the terminal view is rendered
    // straight away and nothing is polled.
    const IMPORT_POLL_INTERVAL_MS = 5000;
    const IMPORT_POLL_MAX_MS = 10 * 60 * 1000;
    let importPollTimer = null;
    let importPollStartedAt = 0;
    let importPollInFlight = false;
    /** @type {string} URL of the staging (generated) course, the fallback while importing. */
    let generatedCourseUrl = '';

    /**
     * Stop polling the import state (terminal state, timeout or page unload).
     *
     * @returns {void}
     */
    const stopImportPolling = () => {
        if (importPollTimer) {
            window.clearInterval(importPollTimer);
            importPollTimer = null;
        }
    };
    window.addEventListener('pagehide', stopImportPolling);

    /**
     * Render the failed state of the import card: the original course was left as it
     * was and the generated (staging) course is still there for the user. No confetti.
     *
     * @param {string} message localized importmessage from the backend, if any
     * @returns {void}
     */
    const renderImportFailed = (message) => {
        stopImportPolling();
        state.currentStage = 'import_failed';
        if (importProgressSpinner) { importProgressSpinner.style.display = 'none'; }
        if (importProgressFailedIcon) { importProgressFailedIcon.style.display = ''; }
        if (importProgressTitle) { importProgressTitle.textContent = texts.courseai_import_failed_title; }
        if (importProgressSummary) { importProgressSummary.textContent = message || texts.import_failed; }
        if (btnOpenGeneratedCourse) { btnOpenGeneratedCourse.disabled = !generatedCourseUrl; }
    };

    /**
     * Apply one session-state answer to the import card.
     *
     * @param {Object} info create_course result or get_course_session_state result
     * @returns {boolean} true when a terminal state (done|failed) was rendered
     */
    const applyImportState = (info) => {
        const importState = String(info?.importstate || '');
        if (importState === 'done') {
            // The copy is the course the user asked for; the staging course is gone.
            // get_course_session_state sends copycourseurl; create_course (foreground
            // execution) only sends copycourseid, so the URL is built from it.
            const copyCourseId = Number(info?.copycourseid || 0);
            const copyCourseUrl = String(info?.copycourseurl || '')
                || (copyCourseId > 0 ? M.cfg.wwwroot + '/course/view.php?id=' + copyCourseId : '');
            showCompletionView({
                success: true,
                importcompleted: true,
                courseid: copyCourseId,
                courseurl: copyCourseUrl || generatedCourseUrl,
            });
            return true;
        }
        if (importState === 'failed') {
            renderImportFailed(String(info?.importmessage || ''));
            return true;
        }
        if (importProgressSummary) {
            const progressText = importState === 'queued' ? texts.import_queued : texts.import_running;
            importProgressSummary.textContent = String(info?.importmessage || '')
                || (importState ? progressText : texts.courseai_import_progress_body);
        }
        return false;
    };

    /**
     * Poll get_course_session_state every 5 s until done|failed, for at most 10 min.
     *
     * @param {number} recordid local session record id
     * @returns {void}
     */
    const startImportPolling = (recordid) => {
        stopImportPolling();
        importPollStartedAt = Date.now();
        importPollInFlight = false;
        importPollTimer = window.setInterval(async() => {
            if (importPollInFlight) {
                return;
            }
            if (Date.now() - importPollStartedAt > IMPORT_POLL_MAX_MS) {
                stopImportPolling();
                if (importProgressSummary) { importProgressSummary.textContent = texts.courseai_import_timeout; }
                return;
            }
            importPollInFlight = true;
            try {
                const info = await CourseaiRepository.getSessionState(recordid);
                applyImportState(info);
            } catch (error) {
                // Transient failure: keep polling until the cap.
            } finally {
                importPollInFlight = false;
            }
        }, IMPORT_POLL_INTERVAL_MS);
    };

    /**
     * Show the "Importing into the existing course..." card and poll until the import
     * ends. `result` is the create_course answer (courseid/courseurl = staging course)
     * plus `recordid`; on resume it may already carry a terminal importstate.
     *
     * @param {Object} result
     * @returns {void}
     */
    const showImportProgressView = (result) => {
        stopImportPolling();
        state.createdCourseResult = result || null;
        state.createdCourseUrl = result?.courseurl || '';
        state.currentStage = 'importing';
        generatedCourseUrl = String(result?.courseurl || '');
        finishGenerationLayout();
        if (completionView) { completionView.style.display = 'none'; }
        if (importProgressSpinner) { importProgressSpinner.style.display = ''; }
        if (importProgressFailedIcon) { importProgressFailedIcon.style.display = 'none'; }
        if (importProgressTitle) { importProgressTitle.textContent = texts.courseai_import_progress_title; }
        if (importProgressSummary) { importProgressSummary.textContent = texts.courseai_import_progress_body; }
        if (btnOpenGeneratedCourse) { btnOpenGeneratedCourse.disabled = !generatedCourseUrl; }
        if (importProgressView) { importProgressView.style.display = 'flex'; }
        if (applyImportState(result)) {
            return;
        }
        const recordid = Number(result?.recordid || state.sessionid || 0);
        if (recordid > 0) {
            startImportPolling(recordid);
        }
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
        state.withSubsections = false;
        state.lang = state.defaultLang;
        state.completionStats = null;
        state.createdCourseUrl = '';
        state.createdCourseResult = null;
        state.initialPrompt = '';
        if (promptInput) { promptInput.value = ''; }
        if (langSelect) { langSelect.value = state.lang; }
        if (btnWithImages) { btnWithImages.checked = false; }
        if (imgToggleWrap) { imgToggleWrap.classList.remove('on'); }
        if (btnWithSubsections) { btnWithSubsections.checked = false; }
        if (subToggleWrap) { subToggleWrap.classList.remove('on'); }
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
        if (btnOpenGeneratedCourse) {
            btnOpenGeneratedCourse.addEventListener('click', () => {
                if (generatedCourseUrl) {
                    window.open(generatedCourseUrl, '_blank', 'noopener,noreferrer');
                }
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
        showImportProgressView,
        showCourseReviewPanel: () => showCourseReviewPanel(
            state, elements, texts, getCourseSettings, FormAutocomplete
        ),
        createCourseFromSession: (overrides = null) => createCourseFromSession(
            state, elements, texts, stepsUi, Notification, createCourse, showCompletionView,
            showImportProgressView, overrides
        ),
        handleGenerate: () => handleGenerate(genCtx()),
        sendFeedbackAction: (action) => sendFeedbackAction(action, fbCtx()),
        resetForAnotherCourse,
        bindEvents,
    };
};
