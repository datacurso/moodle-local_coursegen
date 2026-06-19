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
     * Rebuild the decision log from the snapshot so reload doesn't lose history.
     * localStorage does not survive reload in this (Moodle popup) context, so the
     * snapshot is the source of truth: re-emit an "AI planned section" entry per
     * section and the user's free-text instructions (skipping the first message,
     * which is the initial prompt already shown above the log).
     *
     * @param {Array} sections - raw plan sections (with names)
     * @param {Object} snapshot - the resume snapshot
     */
    const rebuildDecisionLog = (sections, snapshot) => {
        if (typeof emitLog !== 'function') {
            return;
        }
        (sections || []).forEach((section) => {
            const name = String(section?.name || '').trim();
            if (!name) {
                return;
            }
            const template = texts?.courseai_log_ai_section || 'AI planned section «{$a}»';
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
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                rebuildDecisionLog(detailedSections, snapshot);
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
            rebuildDecisionLog(detailedSections, snapshot);
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
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                rebuildDecisionLog(detailedSections, snapshot);
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
