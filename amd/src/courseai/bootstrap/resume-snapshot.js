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
import {rebuildTranscriptFromPlan} from 'local_coursegen/local/courseai/ui/plan-transcript';

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
 * @param {Function} params.parseJsonField
 * @param {Function} params.normalizeSnapshotStatus
 * @param {Function} params.buildSectionsFromDetailedPlan
 * @param {Function} params.buildCourseUrlFromResume
 * @param {Function} params.applyCourseTitleToHeader
 * @param {Function} params.setPlanningStreamVisible
 * @param {Function} params.hydrateDetailedPlanFromSnapshot
 * @param {number} params.resumeSessionId
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
    parseJsonField,
    normalizeSnapshotStatus,
    buildSectionsFromDetailedPlan,
    buildCourseUrlFromResume,
    applyCourseTitleToHeader,
    setPlanningStreamVisible,
    hydrateDetailedPlanFromSnapshot,
    resumeSessionId,
    emitLog,
    texts,
}) => {
    /**
     * Read the distinct user turns out of a snapshot, in checkpoint order.
     *
     * Consecutive duplicates are dropped: a resumed stream can re-store the same
     * instruction, and the live feed never showed it twice.
     *
     * @param {Object} snapshot - the resume snapshot
     * @param {string} initialPrompt - fallback when the checkpoint has no messages yet
     * @returns {Array<string>} the user turns, oldest first
     */
    const readUserTurns = (snapshot, initialPrompt) => {
        const messages = Array.isArray(snapshot?.messages) ? snapshot.messages : [];
        const turns = [];
        messages.forEach((message) => {
            if (!message || message.type !== 'human') {
                return;
            }
            const content = String(message.content || '').trim();
            if (content && content !== turns[turns.length - 1]) {
                turns.push(content);
            }
        });
        const firstPrompt = String(initialPrompt || '').trim();
        if (!turns.length && firstPrompt) {
            turns.push(firstPrompt);
        }
        return turns;
    };

    /**
     * The assistant milestone that closed a given round, worded as the live
     * stream worded it (handlers-lifecycle.js): the first round announces the
     * plan, later rounds announce the applied changes, and the round the session
     * is currently paused on announces the proposals when there are any.
     *
     * @param {number} index - zero-based round
     * @param {number} lastIndex - index of the most recent answered round
     * @param {string} status - the normalized snapshot status
     * @param {boolean} hasProposals - the paused round carries pending proposals
     * @returns {Object} emitLog parameters
     */
    const milestoneForRound = (index, lastIndex, status, hasProposals) => {
        if (index === lastIndex && status === 'COMPLETED') {
            return {
                actor: 'ai',
                kind: 'success',
                message: (texts && texts.courseai_log_ai_completed) || 'Your course is ready. I created it in Moodle.',
            };
        }
        let message;
        if (index === lastIndex && hasProposals) {
            message = (texts && texts.courseai_log_ai_proposals_ready)
                || 'I prepared a few suggestions for you. Review them and choose how you want to continue.';
        } else if (index === 0) {
            message = (texts && texts.courseai_log_ai_review_ready)
                || 'I finished planning your course. Take a look at the plan and tell me if you want any changes.';
        } else {
            message = (texts && texts.courseai_log_ai_review_updated)
                || 'I applied your changes. Take a look and tell me if you want anything else.';
        }
        return {actor: 'ai', kind: 'ai', message};
    };

    /**
     * Rebuild the conversation thread from the snapshot so reload doesn't lose
     * history (§7.1). localStorage does not survive reload in this (Moodle popup)
     * context, so the snapshot is the source of truth.
     *
     * The checkpoint carries BOTH sides of the conversation, but the assistant
     * entries in it are internal notes written for the resolver ("I proposed
     * these options: …"), not user-facing copy. So the user turns are replayed
     * verbatim and each answered round is closed with the same localized
     * milestone the live stream emitted, alternating in the same order the user
     * saw: prompt, plan, milestone, prompt, milestone…
     *
     * Ordering matters as much as content. The feed has two containers and
     * makeEmitLog routes between them, so state.threadBelowPlan is raised as soon
     * as the plan card is rebuilt — otherwise every later turn lands in #cgLog,
     * ABOVE the plan, and the transcript reads out of order. That flag exists
     * precisely so this rebuild does NOT touch planEverReviewed, which also
     * decides which checklist streamed sections fill: raising it here would send
     * the re-opened stream into a fresh round checklist and duplicate the plan.
     *
     * @param {Array} sections - raw plan sections (with names)
     * @param {Object} snapshot - the resume snapshot
     * @param {string} initialPrompt - the first user prompt (becomes turn 1)
     * @param {string} status - the normalized snapshot status
     * @returns {void}
     */
    const rebuildChatFromState = (sections, snapshot, initialPrompt, status) => {
        if (typeof emitLog !== 'function') {
            return;
        }
        const turns = readUserTurns(snapshot, initialPrompt);
        const hasPlan = (sections || []).length > 0;
        const hasProposals = Array.isArray(snapshot?.proposals) && snapshot.proposals.length > 0;
        // At a settled status the graph is paused waiting for the user, so every
        // turn already has its reply. While planning or generating the stream is
        // re-opened and emits the pending reply itself, so the newest turn is
        // left open here instead of being answered twice.
        const settled = status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST' || status === 'COMPLETED';
        const answered = settled ? turns.length : Math.max(0, turns.length - 1);

        turns.forEach((turn, index) => {
            emitLog({actor: 'user', kind: 'user', message: turn});
            if (index === 0 && hasPlan) {
                // rebuildTranscriptFromPlan fills the checklist items AND un-hides
                // the card — one call, no empty card.
                rebuildTranscriptFromPlan(sections);
                state.threadBelowPlan = true;
            }
            if (index < answered) {
                emitLog(milestoneForRound(index, answered - 1, status, hasProposals));
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

        planningUi.syncCompactChatState();

        const detailedSections = Array.isArray(snapshot.detailed_plan_sections)
            ? snapshot.detailed_plan_sections
            : [];
        const sectionsForUi = buildSectionsFromDetailedPlan(detailedSections);

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
                rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
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
            rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
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
                rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
            }
            stepsUi.setStepState('planning', 'done');
            stepsUi.setStepState('generating', 'active');
            state.currentStage = 'generating';
            state.phase4TotalActivities = state.totalActivities;
            streamManager.openSSEStream(state.streamingurl, 0, 'generating', true);
            return true;
        }

        if (status === 'WAITING_SUBSECTIONS_DECISION' && snapshot.subsections_decision) {
            // Paused BEFORE planning at the subsections decision: replay the
            // transcript (initial prompt + the question) and re-render the
            // decision card instead of re-opening the planning stream.
            stepsUi.transitionToPlanning();
            setPlanningStreamVisible();
            applyCourseTitleToHeader();
            rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
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
            // Same as above: hydrate names + log first, then re-open the planning
            // stream with keepPlan=true. Without this the re-stream renders
            // placeholder "Section N:" rows (it re-emits activity events but not
            // section names), which is the reload-broken case from the field.
            if (sectionsForUi.length > 0) {
                await hydrateDetailedPlanFromSnapshot(detailedSections);
                rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
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
                rebuildChatFromState(detailedSections, snapshot, initialPrompt, status);
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
