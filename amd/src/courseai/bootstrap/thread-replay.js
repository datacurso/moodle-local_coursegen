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

import {rebuildTranscriptFromPlan} from 'local_coursegen/local/courseai/ui/plan-transcript';
import {rebuildRegenFromPlan} from 'local_coursegen/local/courseai/ui/regen-block';
import {getActivityLabels} from 'local_coursegen/local/courseai/utils';

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
    ai_course_configuration: 'ai',
    ai_review_ready: 'ai',
    ai_proposals_ready: 'ai',
    ai_completed: 'success',
    ai_failed: 'danger',
    ai_error: 'danger',
};

/**
 * Milestone types whose displayed text must match the LIVE turn (the plugin's
 * own phrasing), NOT the service's generic catalog string. Maps the type to the
 * prefetched lang key the live handler uses, so reload === live.
 */
const MILESTONE_PLUGIN_TEXT = {
    ai_review_ready: 'courseai_log_ai_review_ready',
    ai_proposals_ready: 'courseai_log_ai_proposals_ready',
    ai_completed: 'courseai_log_ai_completed',
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
 * @param {Object} [params.texts] - Prefetched lang strings (milestone phrasing must match live).
 * @returns {{replayThread: Function, renderThreadMessage: Function}}
 */
export const makeThreadReplay = ({state, emitLog, localizeMessage, renderProposals, texts}) => {
    // Activity type → localized label ("book" → "Book"), to compose a replan turn
    // identically to the live handler (activity-dom.js).
    const activityLabels = getActivityLabels(texts || {});

    /**
     * Prefetched lang string with an English fallback.
     *
     * @param {string} key - Lang string id.
     * @param {string} fallback - English fallback when the string is absent.
     * @returns {string}
     */
    const T = (key, fallback) => (texts && texts[key]) || fallback;

    /**
     * Render a user_action turn EXACTLY as its LIVE handler does (canonical),
     * from the prefetched lang strings + the message's frozen payload — so reload
     * matches live verbatim. Returns null for subtypes handled elsewhere
     * (delete_x / reorder_activities via string_args; replan_x composed above),
     * which fall through to the catalog/string_args path.
     *
     * @param {string} subtype
     * @param {Object} payload
     * @returns {string|null}
     */
    const liveUserTurn = (subtype, payload) => {
        const raw = String((payload && payload.instruction) || '').trim();
        // The live feedback/proposal turns truncate the user's text at 80 chars.
        const clip = raw.length > 80 ? raw.slice(0, 80) + '…' : raw;
        switch (subtype) {
            case 'accept': return T('courseai_log_user_approved', 'You approved the plan');
            case 'adjust':
            case 'feedback': return clip;
            case 'proposal_custom': return T('courseai_log_proposal_applied', 'You applied') + ': ' + clip;
            case 'proposals_dismissed': return T('courseai_log_proposals_dismissed', 'You dismissed suggestions');
            case 'stop': return T('courseai_btn_stop', 'Stop');
            case 'resume': return T('courseai_btn_resume', 'Resume');
            case 'add_section': return T('courseai_log_added_section', 'You added a section');
            case 'add_activity': return T('courseai_log_added_activity', 'You added an activity');
            case 'discard_image': return T('courseai_log_image_discarded', 'You discarded an image suggestion');
            case 'replan_image': return T('courseai_log_image_regenerated', 'You regenerated an image suggestion');
            default: return null;
        }
    };

    /**
     * Compose a replan_activity user turn EXACTLY as the live handler does
     * ("{instruction} — {TypeLabel}: {title}"), so reload === live. Reads the
     * title + type FROZEN into the message payload at persist time (NOT from the
     * current plan), so the turn stays historically accurate even if the activity
     * changes later. Returns null when the message has no frozen detail (older
     * pre-fix messages → caller falls back to the generic persisted label).
     *
     * @param {Object} payload - user_action payload ({title, activity_type, instruction}).
     * @returns {string|null}
     */
    const composeReplanActivityTurn = (payload) => {
        const activityTitle = String((payload && payload.title) || '').trim();
        if (!activityTitle) {
            return null;
        }
        const activityType = (payload && payload.activity_type) || '';
        const typeLabel = (activityLabels && activityLabels[activityType]) || activityType || '';
        const target = typeLabel ? typeLabel + ': ' + activityTitle : activityTitle;
        const instruction = String((payload && payload.instruction) || '').trim();
        return instruction ? instruction + ' — ' + target : target;
    };

    /**
     * Compose a replan_section user turn EXACTLY as the live handler does
     * (section-dom.js): "{instruction} — {SectionWord}: {name}" with an
     * instruction, else "You regenerated section: {name}". Reads the section name
     * FROZEN into the payload at persist time. Returns null when absent.
     *
     * @param {Object} payload - user_action payload ({name, instruction}).
     * @returns {string|null}
     */
    const composeReplanSectionTurn = (payload) => {
        const name = String((payload && payload.name) || '').trim();
        if (!name) {
            return null;
        }
        const instruction = String((payload && payload.instruction) || '').trim();
        if (instruction) {
            const word = (texts && texts.courseai_section_word) || 'Section';
            return instruction + ' — ' + word + ': ' + name;
        }
        return ((texts && texts.courseai_log_regenerated_section) || 'You regenerated section: {$a}')
            .replace('{$a}', name);
    };

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
            // Replan an activity: compose the SAME detailed turn the live handler
            // shows ("{instruction} — {TypeLabel}: {title}") so reload === live,
            // instead of the generic persisted label. Falls back to the generic
            // path when the target can't be resolved.
            if (subtype === 'replan_activity') {
                const composed = composeReplanActivityTurn(payload);
                if (composed) {
                    emitLog({actor: 'user', kind, message: composed});
                    return;
                }
            }
            if (subtype === 'replan_section' || subtype === 'replan_sections') {
                const composed = composeReplanSectionTurn(payload);
                if (composed) {
                    emitLog({actor: 'user', kind, message: composed});
                    return;
                }
            }
            // Applying a picked proposal: render "You applied: <summary>" from the
            // frozen proposal summary, exactly as the live card turn did.
            if (subtype === 'proposal_applied') {
                const applied = T('courseai_log_proposal_applied', 'You applied');
                const summaryText = (payload && payload.summary)
                    ? await resolveText(payload.summary, localizeMessage)
                    : '';
                const clip = summaryText.length > 80 ? summaryText.slice(0, 80) + '…' : summaryText;
                emitLog({actor: 'user', kind, message: clip ? applied + ': ' + clip : applied});
                return;
            }
            // Subtypes whose live turn is a fixed plugin phrasing (stop/resume/
            // accept/add/dismiss/images/adjust): render the SAME text live shows.
            const liveText = liveUserTurn(subtype, payload);
            if (liveText !== null) {
                if (liveText) {
                    emitLog({actor: 'user', kind, message: liveText});
                }
                return;
            }
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
            // Prefer rebuilding the per-section transcript from the persisted
            // structured plan (payload.plan) so reload renders EXACTLY like the
            // live per-section transcript. Fall back to the pre-rendered Markdown
            // block (content.string) for old sessions that have no payload.plan.
            const planTree = (payload && Array.isArray(payload.plan)) ? payload.plan : null;
            if (planTree && planTree.length) {
                // If this round was a REPLAN (the preceding user_action was a
                // replan_activity/replan_section), render its regenerated subtree as
                // a NEW block below the instruction — mirroring the live build — and
                // leave the top "structure I planned" checklist frozen. Otherwise
                // (initial plan or reorder) rebuild the top as before, preserving the
                // already-verified reorder reload parity.
                if (ctx.regenScope && ctx.regenScope.action === 'replan_activity') {
                    rebuildRegenFromPlan({
                        action: ctx.regenScope.action,
                        targetIds: ctx.regenScope.targetIds,
                        plan: planTree,
                    });
                } else {
                    rebuildTranscriptFromPlan(planTree);
                }
                return;
            }
            const header = await resolveText(content, localizeMessage);
            const body = String((content && content.string) || '').trim();
            const text = body && header && header !== body
                ? header + '\n\n' + body
                : (body || header);
            if (text) {
                emitLog({actor: 'ai', kind: 'ai', message: text, markdown: true});
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

        // 5. AI lifecycle milestones — single AI turn line. For review/proposals/
        // completed, use the SAME plugin phrasing the live handler emits (so reload
        // matches live exactly); otherwise fall back to the server-rendered text.
        if (Object.prototype.hasOwnProperty.call(AI_MILESTONE_KIND, type)) {
            let pluginKey = MILESTONE_PLUGIN_TEXT[type];
            // The first review milestone follows the INITIAL planning; later ones
            // follow a user adjustment. Mirror the live phrasing so reload === live:
            // only the FIRST review_ready says "I finished planning…", the rest say
            // "I applied your changes…". (ctx.firstReview is set by replayThread,
            // which counts review milestones — planEverReviewed can't be used here
            // because the preceding ai_proposals_card already flips it.)
            if (type === 'ai_review_ready' && ctx.firstReview === false) {
                pluginKey = 'courseai_log_ai_review_updated';
            }
            const pluginText = pluginKey && texts && texts[pluginKey];
            const text = pluginText || await resolveText(content, localizeMessage);
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
        // Count review milestones (review_ready / proposals_ready) as we go: the
        // 1st marks the initial-planning review, every later one a post-adjustment
        // review. Lets renderThreadMessage pick the matching milestone phrasing.
        let reviewMilestones = 0;
        // When the previous message was a replan user_action, the NEXT
        // ai_planned_structure renders as a regeneration block (not a top rebuild).
        let pendingRegen = null;
        for (let i = 0; i < ordered.length; i++) {
            const msg = ordered[i];
            const t = msg.type;
            const isReviewMilestone = t === 'ai_review_ready' || t === 'ai_proposals_ready';
            if (isReviewMilestone) {
                reviewMilestones += 1;
            }
            // Sequential await is intentional: turns must render in order, and
            // each emitLog appends to the live feed.
            // eslint-disable-next-line no-await-in-loop
            await renderThreadMessage(msg, {
                atReview: Boolean(ctx.atReview),
                isLast: i === lastIndex,
                firstReview: isReviewMilestone ? reviewMilestones === 1 : undefined,
                regenScope: pendingRegen,
            });
            // A replan user_action arms the regen scope for the following
            // ai_planned_structure; any other user_action clears it (reorder/add/
            // delete rebuild the top normally).
            if (t === 'user_action') {
                const sub = (msg.payload && msg.payload.subtype) || '';
                pendingRegen = (sub === 'replan_activity' || sub === 'replan_section')
                    ? {action: sub, targetIds: (msg.payload && msg.payload.target_ids) || []}
                    : null;
            }
        }
    };

    return {replayThread, renderThreadMessage};
};
