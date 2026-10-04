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
 * Rebuild the conversation of a resumed session from its snapshot.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-thread
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {rebuildTranscriptFromPlan} from 'local_coursegen/local/courseai/ui/plan-transcript';

/**
 * Build the rebuildThread async function.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Function} params.emitLog
 * @param {Object} params.texts - localized strings (for reconstructed AI milestones)
 * @param {Function} params.replayThread - replay the service thread (preferred path)
 * @returns {Function} async rebuildThread(sections, snapshot, initialPrompt, status) function
 */
export const makeRebuildThread = ({state, emitLog, texts, replayThread}) => {
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
     * Rebuild the transcript for a resumed session.
     *
     * The service thread is the source of truth: it carries every turn as it
     * happened, including the action payloads ("You applied: move «Basics»
     * after «Advanced»") that exist nowhere else once the plan has moved on.
     * Replaying it is the only way a reload matches what was on screen.
     *
     * Sessions started before the thread was recorded carry none, so they fall
     * back to the round-by-round rebuild, which reads the checkpoint messages
     * and can only approximate: user turns verbatim, one milestone per round.
     *
     * @param {Array} sections - raw plan sections (with names)
     * @param {Object} snapshot - the resume snapshot
     * @param {string} initialPrompt - the first user prompt
     * @param {string} status - the normalized snapshot status
     * @returns {Promise<void>}
     */
    const rebuildThread = async(sections, snapshot, initialPrompt, status) => {
        const thread = Array.isArray(snapshot?.thread) ? snapshot.thread : [];
        if (thread.length > 0 && typeof replayThread === 'function') {
            // The thread carries each AI output block in full, which replaces the
            // names-only checklist card as the history of the plan; the centre
            // panel still renders the live plan separately.
            const atReview = status === 'WAITING_APPROVAL' || status === 'PLANNING_ADJUST';
            await replayThread(thread, {atReview});
            return;
        }
        rebuildChatFromState(sections, snapshot, initialPrompt, status);
    };

    return rebuildThread;
};
