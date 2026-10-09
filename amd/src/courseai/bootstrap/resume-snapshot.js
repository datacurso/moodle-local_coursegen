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

import {renderSubsectionsDecision} from 'local_coursegen/local/courseai/ui/subsections-decision';
import {makeRebuildThread} from 'local_coursegen/courseai/bootstrap/resume-thread';
import {eventsToReplay} from 'local_coursegen/courseai/bootstrap/resume-replay';
import {askForCourseDetails, showFailure} from 'local_coursegen/courseai/bootstrap/resume-end-states';
import {showSyllabusChip} from 'local_coursegen/courseai/bootstrap/resume-syllabus-chip';
import {hideSkeletons, isWaitingForFirstSection} from 'local_coursegen/courseai/bootstrap/resume-skeleton';
import {showApprovedPlan, showGeneratedPlan} from 'local_coursegen/courseai/bootstrap/resume-generated-plan';

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
 * @param {Object} params.proposalsUi
 * @param {Object} params.streamManager
 * @param {Object} params.actions
 * @param {Function} params.createCourseFromSession - opens the course details form and creates the course
 * @param {Function} params.parseJsonField
 * @param {Function} params.normalizeSnapshotStatus
 * @param {Function} params.buildSectionsFromDetailedPlan
 * @param {Function} params.buildCourseUrlFromResume
 * @param {Function} params.applyCourseTitleToHeader
 * @param {Function} params.setPlanningStreamVisible
 * @param {Function} params.hydrateDetailedPlanFromSnapshot
 * @param {number} params.resumeSessionId
 * @param {Function} params.replayThread - replay the service thread (preferred path)
 * @param {Function} params.emitLog
 * @param {Object} params.texts - localized strings (for reconstructed AI milestones)
 * @returns {Function} async resumeFromSnapshot function
 */
export const makeResumeFromSnapshot = ({
    state,
    elements,
    CourseaiRepository,
    stepsUi,
    planningUi,
    detailedUi,
    proposalsUi,
    streamManager,
    actions,
    createCourseFromSession,
    parseJsonField,
    normalizeSnapshotStatus,
    buildSectionsFromDetailedPlan,
    buildCourseUrlFromResume,
    applyCourseTitleToHeader,
    setPlanningStreamVisible,
    hydrateDetailedPlanFromSnapshot,
    resumeSessionId,
    replayThread,
    emitLog,
    texts,
}) => {
    const rebuildThread = makeRebuildThread({state, emitLog, texts, replayThread});

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
        // What the running phase has emitted so far; empty when nothing is running.
        const replay = eventsToReplay(status, snapshot);

        state.sessionid = Number(resume.recordid || resumeSessionId);
        state.threadid = String(resume.sessionid || '');
        state.streamingurl = String(resume.streamingurl || '');
        state.lang = snapshot?.request_config?.lang || coursedata.local_coursegen_lang || state.defaultLang;
        state.withImages = Boolean(
            snapshot?.request_config?.with_images
            ?? coursedata.local_coursegen_with_images
            ?? false
        );
        state.withSubsections = Boolean(
            snapshot?.request_config?.with_subsections
            ?? coursedata.local_coursegen_generate_subsections
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
        if (elements.btnWithSubsections) {
            elements.btnWithSubsections.checked = state.withSubsections;
        }
        if (elements.subToggleWrap) {
            elements.subToggleWrap.classList.toggle('on', state.withSubsections);
        }

        showSyllabusChip(document, resume.syllabusname);
        planningUi.syncCompactChatState();

        const detailedSections = Array.isArray(snapshot.detailed_plan_sections)
            ? snapshot.detailed_plan_sections
            : [];
        const sectionsForUi = buildSectionsFromDetailedPlan(detailedSections);

        // Real content is about to render: drop the in-place boot skeletons so they never overlap the
        // hydrated plan, unless the run has not drawn its first section yet and the live stream
        // would still be showing them.
        if (!isWaitingForFirstSection(status, sectionsForUi, replay)) {
            hideSkeletons(document);
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

        if (status === 'PLANNING_ADJUST' && replay.length > 0) {
            // An adjustment is running, not paused: draw the plan, then replay what the adjustment
            // has emitted and follow the rest live, as the stream of an adjustment does.
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            await hydrateDetailedPlanFromSnapshot(detailedSections);
            await rebuildThread(detailedSections, snapshot, initialPrompt, 'PLANNING');
            state.planEverReviewed = true;
            streamManager.openSSEStream(state.streamingurl, 0, 'planning', true, replay);
            return true;
        }

        if (status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            await hydrateDetailedPlanFromSnapshot(detailedSections);
            await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            // The plan is at review: future log entries (e.g. the user's next
            // feedback) must flow at the END of the feed. rebuildChatFromState
            // already flips this when it rebuilds the plan card; set it here too
            // for the case where the snapshot arrived without sections.
            state.planEverReviewed = true;
            if (typeof detailedUi.enableAllActionControls === 'function') {
                detailedUi.enableAllActionControls();
            }
            // The session is paused ON the proposals: re-render the same card the
            // live interrupt rendered, or the options the user was asked to pick
            // from are simply gone after a reload.
            if (proposalsUi && typeof proposalsUi.renderProposals === 'function') {
                proposalsUi.renderProposals({
                    proposals: snapshot.proposals,
                    fallen_proposals: snapshot.fallen_proposals,
                    clarification: snapshot.clarification,
                });
            }
            planningUi.showReviewActions('detailed');
            return true;
        }

        if (status === 'GENERATING' || status === 'PLANNING_ACCEPT') {
            // The plan is already approved → the composer stays hidden (the course is
            // created and cannot be edited from this wizard). Set the flag BEFORE the
            // generation stream re-opens so its setCompactChatState calls collapse to
            // hidden, and hide the card now in case it was shown on resume.
            state.planApproved = true;
            document.body.classList.add('cg-plan-approved');
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
            showApprovedPlan({root: document, detailedUi});
            stepsUi.setStepState('planning', 'done');
            stepsUi.setStepState('generating', 'active');
            state.currentStage = 'generating';
            state.phase4TotalActivities = state.totalActivities;
            streamManager.openSSEStream(state.streamingurl, 0, 'generating', true, replay);
            return true;
        }

        if (status === 'WAITING_SUBSECTIONS_DECISION' && snapshot.subsections_decision) {
            // Paused BEFORE planning at the subsections decision: replay the
            // transcript (initial prompt + the question) and re-render the
            // decision card instead of re-opening the planning stream.
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            await renderSubsectionsDecision({
                data: snapshot.subsections_decision,
                ctx: {
                    state,
                    texts,
                    emitLog,
                    stepsUi,
                    openSSEStream: streamManager.openSSEStream,
                },
            });
            return true;
        }

        if (status === 'PLANNING' || status === 'PENDING') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            if (replay.length > 0) {
                // The snapshot carries everything the phase has emitted: draw it by replaying it
                // through the handlers of the live stream, as the stream drew it, and follow the
                // rest live. Drawing the plan from the snapshot as well would draw it twice.
                state.latestInitialSections = [];
                state.totalSections = 0;
                state.totalActivities = 0;
                await rebuildThread([], snapshot, initialPrompt, status);
                streamManager.openSSEStream(state.streamingurl, 0, 'planning', false, replay);
                return true;
            }
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
            // Course already created → no composer (it cannot be edited from here).
            // The body class hides it via CSS regardless of the showReviewActions
            // call below (which would otherwise re-enable the composer).
            state.planApproved = true;
            document.body.classList.add('cg-plan-approved');
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            }
            // The plan stays as the live stream leaves it when it completes, not as an editable plan.
            showGeneratedPlan({state, texts, root: document});
            if (typeof detailedUi.enableAllActionControls === 'function') {
                detailedUi.enableAllActionControls();
            }
            // The generation is done but the course is not created yet: the user still has to confirm
            // its details, so the page asks for them again, as the live stream does when it completes.
            stepsUi.setStepState('planning', 'done');
            stepsUi.setStepState('generating', 'active');
            state.currentStage = 'generating';
            stepsUi.updateFlowNav();
            askForCourseDetails({createCourseFromSession, emitLog, texts});
            return true;
        }

        if (status === 'FAILED') {
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                await rebuildThread(detailedSections, snapshot, initialPrompt, status);
            }
            showFailure({state, stepsUi, detailedUi, emitLog, texts});
            return true;
        }

        return false;
    };
};
