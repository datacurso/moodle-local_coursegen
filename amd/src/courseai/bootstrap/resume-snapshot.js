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
 * @param {Object} params.texts
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
}) => {
    /**
     * Rebuild the conversation thread from the snapshot so reload doesn't lose
     * history (§7.1). localStorage does not survive reload in this (Moodle popup)
     * context, so the snapshot is the source of truth. The thread is rearmed in
     * chronological order: the initial prompt as the FIRST user turn, then an
     * "AI planned section" turn per section, then any later user instructions.
     *
     * @param {Array} sections - raw plan sections (with names)
     * @param {Object} snapshot - the resume snapshot
     * @param {string} initialPrompt - the first user prompt (becomes turn 1)
     * @returns {void}
     */
    const rebuildDecisionLog = (sections, snapshot, initialPrompt) => {
        if (typeof emitLog !== 'function') {
            return;
        }
        const firstPrompt = String(initialPrompt || '').trim();
        if (firstPrompt) {
            emitLog({actor: 'user', kind: 'user', message: firstPrompt});
        }
        (sections || []).forEach((section) => {
            const name = String(section?.name || '').trim();
            if (!name) {
                return;
            }
            const template = texts?.courseai_log_ai_section || 'AI planned section: {$a}';
            emitLog({actor: 'ai', kind: 'ai', message: template.replace('{$a}', name)});
        });
        const messages = Array.isArray(snapshot?.messages) ? snapshot.messages : [];
        messages
            .filter((message) => message && message.type === 'human')
            .slice(1)
            .forEach((message) => {
                const content = String(message.content || '').trim();
                if (content) {
                    emitLog({actor: 'user', kind: 'user', message: content});
                }
            });
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
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                rebuildDecisionLog(detailedSections, snapshot, initialPrompt);
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
            rebuildDecisionLog(detailedSections, snapshot, initialPrompt);
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
                rebuildDecisionLog(detailedSections, snapshot, initialPrompt);
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
                rebuildDecisionLog(detailedSections, snapshot, initialPrompt);
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
                rebuildDecisionLog(detailedSections, snapshot, initialPrompt);
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
