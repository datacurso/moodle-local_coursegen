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
 * Replay the server-side message thread into the left decision-log feed.
 *
 * The SERVICE persists every renderable thread message (user prompts, user
 * actions, AI planning outputs, lifecycle milestones) as an ordered, typed log
 * and ships it in `snapshot.thread`. On reload the plugin replays that log
 * verbatim in ascending `seq` order, so the LEFT panel shows the FULL planning
 * transcript in plain text (every AI output in full plus every user action, in
 * order). The CENTER preview keeps showing only the latest reconciled plan
 * (hydrate-plan + reconciler, untouched by this module).
 *
 * This REPLACES the lossy heuristic reconstruction (`rebuildDecisionLog`) for
 * sessions that carry a thread; old thread-less sessions fall back to it.
 *
 * @module     local_coursegen/courseai/bootstrap/thread-replay
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * AI-output message types whose visible content is the FULL pre-rendered plain
 * text of that planning step (`content.string`). They render as an AI turn
 * block reusing the log styling + fade/expand clamp.
 */
const AI_OUTPUT_TYPES = new Set(['ai_planned_structure']);

/**
 * AI lifecycle/milestone types that render as a single AI turn line.
 * The map value is the log `kind`.
 */
const AI_MILESTONE_KIND = {
    ai_course_identity: 'ai',
    ai_review_ready: 'ai',
    ai_proposals_ready: 'ai',
    ai_completed: 'success',
    ai_failed: 'danger',
    ai_error: 'danger',
};

/**
 * Milestone types that mark the plan as reviewed at least once, so subsequent
 * turns land below the checklist (#cgLogAfter) exactly as they do live.
 */
const REVIEW_MILESTONES = new Set(['ai_review_ready', 'ai_proposals_ready', 'ai_proposals_card']);

/**
 * Map a `user_action` subtype to the log `kind` that matches the live visual
 * language (success for additive/approve, danger for destructive, info for
 * proposal applied, neutral for dismiss/stop/resume, user for free-text/reorder).
 */
const USER_ACTION_KIND = {
    accept: 'success',
    adjust: 'user',
    proposal_applied: 'info',
    proposal_custom: 'info',
    proposals_dismissed: 'neutral',
    stop: 'neutral',
    resume: 'neutral',
    add_section: 'success',
    add_activity: 'success',
    delete_section: 'danger',
    delete_activity: 'danger',
    reorder_sections: 'user',
    reorder_activities: 'user',
    replan_section: 'user',
    replan_activity: 'user',
    discard_image: 'danger',
    replan_image: 'user',
};

/**
 * Resolve the human-readable text for a thread message's `content`.
 *
 * Localizes by `content.string_id` (+ `string_args`) against the Moodle lang
 * pack, falling back to the server-rendered `content.string` for free-form text
 * (`string_id === null`). Returns an empty string when there is nothing to show.
 *
 * @param {Object} content - { string_id, string, string_args? }
 * @param {Function} localizeMessage - async localizer (string_id → text).
 * @returns {Promise<string>}
 */
const resolveText = async(content, localizeMessage) => {
    if (!content) {
        return '';
    }
    // Free-form text (no catalog key): render the server string verbatim.
    if (!content.string_id) {
        return String(content.string || '').trim();
    }
    const localized = await localizeMessage(content);
    return String(localized || content.string || '').trim();
};

/**
 * Build the thread replay helpers.
 *
 * @param {Object} params
 * @param {Object} params.state - shared courseai state (planEverReviewed flag).
 * @param {Function} params.emitLog - decision-log emitter ({actor, kind, message}).
 * @param {Function} params.localizeMessage - async localizer for LocalizedMessage.
 * @param {Function} [params.renderProposals] - ui-proposals renderProposals(payload).
 * @returns {{replayThread: Function, renderThreadMessage: Function}}
 */
export const makeThreadReplay = ({state, emitLog, localizeMessage, renderProposals}) => {
    /**
     * Render a single thread message into the left feed.
     *
     * @param {Object} msg - { seq, type, role, content, payload, created_at }
     * @param {Object} [ctx] - replay context.
     * @param {boolean} [ctx.atReview] - session is currently waiting at review
     *     (so the latest proposals card may render interactively).
     * @param {boolean} [ctx.isLast] - this is the last message in the thread.
     * @returns {Promise<void>}
     */
    const renderThreadMessage = async(msg, ctx = {}) => {
        if (!msg || typeof emitLog !== 'function') {
            return;
        }
        const type = msg.type;
        const content = msg.content || null;
        const payload = msg.payload || null;

        // Mark the plan reviewed when the first review milestone replays, so any
        // turns appended afterwards land in #cgLogAfter — mirroring live flow.
        if (REVIEW_MILESTONES.has(type)) {
            state.planEverReviewed = true;
        }

        // 1. Initial user prompt — full free-form text.
        if (type === 'user_prompt') {
            const text = await resolveText(content, localizeMessage);
            if (text) {
                emitLog({actor: 'user', kind: 'user', message: text});
            }
            return;
        }

        // 2. User action — kind chosen from subtype; localized label or fallback.
        if (type === 'user_action') {
            const subtype = (payload && payload.subtype) || '';
            const kind = USER_ACTION_KIND[subtype] || 'user';
            const label = await resolveText(content, localizeMessage);
            // Show the user's own words when the action carried free text (adjust,
            // custom proposal, replan…), so the transcript keeps the instruction.
            const instruction = String((payload && payload.instruction) || '').trim();
            const text = instruction ? label + ': ' + instruction : label;
            if (text) {
                emitLog({actor: 'user', kind, message: text});
            }
            return;
        }

        // 3. AI planning output — render the FULL pre-rendered plain-text plan as
        // an AI turn block. The log body uses white-space:pre-wrap and the
        // existing fade/expand clamp, so newlines and indentation are preserved.
        if (AI_OUTPUT_TYPES.has(type)) {
            // The FULL pre-rendered plan lives in content.string; the catalog
            // string_id is only a short header label. Show the header + the full
            // plain-text body so the left panel is the complete transcript, not a
            // one-line label.
            const header = await resolveText(content, localizeMessage);
            const body = String((content && content.string) || '').trim();
            const text = body && header && header !== body
                ? header + '\n\n' + body
                : (body || header);
            if (text) {
                emitLog({actor: 'ai', kind: 'ai', message: text});
            }
            return;
        }

        // 4. Interactive proposals card — re-render ONLY for the latest message
        // when the session is still at review. Otherwise show the milestone line.
        if (type === 'ai_proposals_card') {
            const canRenderCard = Boolean(ctx.atReview) && Boolean(ctx.isLast)
                && typeof renderProposals === 'function' && payload;
            if (canRenderCard) {
                await renderProposals(payload);
                return;
            }
            const text = await resolveText(content, localizeMessage);
            if (text) {
                emitLog({actor: 'ai', kind: 'ai', message: text});
            }
            return;
        }

        // 5. AI lifecycle milestones — single AI turn line.
        if (Object.prototype.hasOwnProperty.call(AI_MILESTONE_KIND, type)) {
            const text = await resolveText(content, localizeMessage);
            if (text) {
                emitLog({actor: 'ai', kind: AI_MILESTONE_KIND[type], message: text});
            }
            return;
        }

        // `status` rows are excluded by the service; any other/unknown type with
        // renderable content degrades to a neutral line so nothing is silently
        // dropped from the transcript.
        const fallback = await resolveText(content, localizeMessage);
        if (fallback) {
            const role = msg.role === 'user' ? 'user' : 'ai';
            emitLog({actor: role, kind: 'neutral', message: fallback});
        }
    };

    /**
     * Replay the full thread into the left feed in ascending `seq` order.
     *
     * @param {Array} thread - ordered thread messages from `snapshot.thread`.
     * @param {Object} [ctx] - replay context.
     * @param {boolean} [ctx.atReview] - session is currently at review.
     * @returns {Promise<void>}
     */
    const replayThread = async(thread, ctx = {}) => {
        if (!Array.isArray(thread) || thread.length === 0) {
            return;
        }
        // Render strictly in ascending seq (defensive copy + sort: the service
        // already orders by seq, but never trust ordering on the wire).
        const ordered = thread.slice().sort((a, b) => Number(a.seq || 0) - Number(b.seq || 0));
        const lastIndex = ordered.length - 1;
        for (let i = 0; i < ordered.length; i++) {
            // Sequential await is intentional: turns must render in order, and
            // each emitLog appends to the live feed.
            // eslint-disable-next-line no-await-in-loop
            await renderThreadMessage(ordered[i], {
                atReview: Boolean(ctx.atReview),
                isLast: i === lastIndex,
            });
        }
    };

    return {replayThread, renderThreadMessage};
};
