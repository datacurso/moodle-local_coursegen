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
 * Resume-from-snapshot logic for the Course AI entrypoint.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-snapshot
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build the resumeFromSnapshot async function.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {Object} params.CourseaiRepository
 * @param {Object} params.stepsUi
 * @param {Object} params.planningUi
 * @param {Object} params.detailedUi
 * @param {Object} params.streamManager
 * @param {Object} params.actions
 * @param {Function} params.parseJsonField
 * @param {Function} params.normalizeSnapshotStatus
 * @param {Function} params.buildSectionsFromDetailedPlan
 * @param {Function} params.buildCourseUrlFromResume
 * @param {Function} params.applyCourseTitleToHeader
 * @param {Function} params.setPlanningStreamVisible
 * @param {Function} params.hydrateDetailedPlanFromSnapshot
 * @param {Function} params.restoreAdjustmentHistory
 * @param {number} params.resumeSessionId
 * @param {Function} params.emitLog
 * @param {Object} params.texts - localized strings (for reconstructed AI milestones)
 * @param {Function} params.replayThread - replay the server-side thread array (preferred path)
 * @returns {Function} async resumeFromSnapshot function
 */
export const makeResumeFromSnapshot = ({
    state,
    elements,
    CourseaiRepository,
    stepsUi,
    planningUi,
    detailedUi,
    streamManager,
    actions,
    parseJsonField,
    normalizeSnapshotStatus,
    buildSectionsFromDetailedPlan,
    buildCourseUrlFromResume,
    applyCourseTitleToHeader,
    setPlanningStreamVisible,
    hydrateDetailedPlanFromSnapshot,
    restoreAdjustmentHistory,
    resumeSessionId,
    emitLog,
    texts,
    replayThread,
}) => {
    /**
     * Rebuild the conversation thread from the snapshot so reload doesn't lose
     * history (§7.1). localStorage does not survive reload in this (Moodle popup)
     * context, so the snapshot is the source of truth. The thread is rearmed in
     * chronological order: the initial prompt as the FIRST user turn, then any
     * later distinct human instructions. The section checklist card (#courseaiChecklist)
     * is un-hidden when sections exist — it acts as the grouped AI planning turn.
     *
     * @param {Array} sections - raw plan sections (with names)
     * @param {Object} snapshot - the resume snapshot
     * @param {string} initialPrompt - the first user prompt (becomes turn 1)
     * @param {string} status - the normalized snapshot status (drives the AI milestone)
     * @returns {void}
     */
    const rebuildDecisionLog = (sections, snapshot, initialPrompt, status) => {
        if (typeof emitLog !== 'function') {
            return;
        }
        const firstPrompt = String(initialPrompt || '').trim();
        if (firstPrompt) {
            emitLog({actor: 'user', kind: 'user', message: firstPrompt});
        }
        // Un-hide the grouped checklist card when sections exist so it serves as
        // the single "AI planned the structure" turn without emitting N flat rows.
        if ((sections || []).length > 0) {
            const checklistEl = document.getElementById('courseaiChecklist');
            if (checklistEl) {
                checklistEl.classList.remove('hidden');
            }
        }
        // Emit remaining DISTINCT human messages (skip messages that duplicate the
        // initial prompt or consecutive duplicates).
        const messages = Array.isArray(snapshot?.messages) ? snapshot.messages : [];
        let lastEmitted = firstPrompt;
        messages
            .filter((message) => message && message.type === 'human')
            .slice(1)
            .forEach((message) => {
                const content = String(message.content || '').trim();
                if (content && content !== lastEmitted) {
                    emitLog({actor: 'user', kind: 'user', message: content});
                    lastEmitted = content;
                }
            });
        // The snapshot stores ONLY human messages, so the assistant's own turns
        // (e.g. "I finished planning your course…") would vanish on reload. Rebuild
        // the AI's closing milestone for the CURRENT state deterministically, placed
        // below the planned-structure group (flag the plan reviewed first so it lands
        // in #cgLogAfter). Generating/planning states reopen the stream, which emits
        // its own fresh milestones, so they are skipped here.
        if (status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST') {
            state.planEverReviewed = true;
            const hasProposals = Array.isArray(snapshot?.proposals) && snapshot.proposals.length > 0;
            const aiMessage = hasProposals
                ? ((texts && texts.courseai_log_ai_proposals_ready)
                    || 'I prepared a few suggestions for you. Review them and choose how you want to continue.')
                : ((texts && texts.courseai_log_ai_review_ready)
                    || 'I finished planning your course. Take a look at the plan and tell me if you want any changes.');
            emitLog({actor: 'ai', kind: 'ai', message: aiMessage});
        } else if (status === 'COMPLETED') {
            state.planEverReviewed = true;
            emitLog({
                actor: 'ai',
                kind: 'success',
                message: (texts && texts.courseai_log_ai_completed) || 'Your course is ready. I created it in Moodle.',
            });
        }
    };
    /**
     * Read the server-side thread array from the snapshot.
     *
     * @param {Object} snapshot - the resume snapshot.
     * @returns {Array} the thread array (empty when absent).
     */
    const getThread = (snapshot) => (Array.isArray(snapshot?.thread) ? snapshot.thread : []);

    /**
     * Rebuild the left decision-log feed for a resumed session.
     *
     * Prefers the SERVICE thread (single source of truth): when `snapshot.thread`
     * is a non-empty array it is replayed verbatim in seq order — the FULL
     * transcript (every user action + each AI output block with its full
     * plain-text content). Old thread-less sessions FALL BACK to the legacy
     * heuristic `rebuildDecisionLog`. The center plan hydration is unaffected.
     *
     * @param {Array} sections - raw plan sections (with names).
     * @param {Object} snapshot - the resume snapshot.
     * @param {string} initialPrompt - the first user prompt.
     * @param {string} status - the normalized snapshot status.
     * @returns {Promise<void>}
     */
    const rebuildThread = async(sections, snapshot, initialPrompt, status) => {
        const thread = getThread(snapshot);
        if (thread.length > 0 && typeof replayThread === 'function') {
            // The full plain-text AI-output blocks in the thread REPLACE the
            // names-only checklist card for history, so it stays hidden here —
            // the center preview still renders the live plan separately.
            const atReview = status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST';
            await replayThread(thread, {atReview});
            return;
        }
        // Back-compat: pre-migration sessions carry no thread → legacy rebuild.
        rebuildDecisionLog(sections, snapshot, initialPrompt, status);
    };
    /**
     * Attempt to resume the page from a persisted session snapshot.
     *
     * @returns {Promise<boolean>} true if the page was resumed from a snapshot
     */
    return async() => {
        if (resumeSessionId <= 0) {
            return false;
        }

        const resume = await CourseaiRepository.getSessionState(resumeSessionId);
        if (!resume || !resume.success) {
            return false;
        }

        // The snapshot arrived and real content is about to render — drop the
        // in-place boot skeletons now so they never overlap the hydrated plan.
        ['cgLeftSkeleton', 'cgCenterSkeleton'].forEach((id) => {
            const skeleton = document.getElementById(id);
            if (skeleton) {
                skeleton.style.display = 'none';
            }
        });

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

        // The server thread is the single source of truth for the left feed; the
        // legacy round/human-message history rebuild only runs for thread-less
        // (pre-migration) sessions to avoid duplicating the transcript.
        if (getThread(snapshot).length === 0) {
            restoreAdjustmentHistory(snapshot?.messages || [], snapshot?.planning_rounds || [], sectionsForUi);
        }

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
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
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
            await hydrateDetailedPlanFromSnapshot(detailedSections);
            await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            // The plan is at review: future log entries (e.g. the user's next
            // feedback) must flow at the END of the feed. Set this AFTER the
            // historical rebuild so the rebuilt planning entries stay on top.
            state.planEverReviewed = true;
            if (typeof detailedUi.enableAllActionControls === 'function') {
                detailedUi.enableAllActionControls();
            }
            planningUi.showReviewActions('detailed');
            return true;
        }

        if (status === 'GENERATING' || status === 'PLANNING_ACCEPT') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            // Hydrate the rendered plan from the snapshot BEFORE re-opening the
            // stream so section names and the decision log survive reload. The
            // stream re-opens with keepPlan=true so it diffs against the hydrated
            // plan instead of clearing it (resetPlanningState early-returns).
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            }
            stepsUi.setStepState('planning', 'done');
            stepsUi.setStepState('generating', 'active');
            state.currentStage = 'generating';
            state.phase4TotalActivities = state.totalActivities;
            streamManager.openSSEStream(state.streamingurl, 0, 'generating', true);
            return true;
        }

        if (status === 'PLANNING' || status === 'PENDING') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            // Same as above: hydrate names + log first, then re-open the planning
            // stream with keepPlan=true. Without this the re-stream renders
            // placeholder "Section N:" rows (it re-emits activity events but not
            // section names), which is the reload-broken case from the field.
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            }
            streamManager.openSSEStream(state.streamingurl, 0, 'planning', true);
            return true;
        }

        if (status === 'COMPLETED') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
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
};
