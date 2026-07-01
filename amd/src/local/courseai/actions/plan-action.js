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
 * Factory for the send-feedback → re-open-stream action used across the planning UI.
 *
 * @module     local_coursegen/local/courseai/actions/plan-action
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {showWorkingIndicator} from 'local_coursegen/local/courseai/ui/feedback-progress';

/**
 * Create a runPlanAction function that sends one ActionIntent as pending_action
 * and re-opens the planning SSE stream.
 *
 * @param {Object}   opts
 * @param {Object}   opts.state                  - Shared mutable state (needs sessionid, streamingurl).
 * @param {Object}   opts.texts                  - Localized strings (for the instant working indicator).
 * @param {Function} opts.sendPlanningFeedback    - WS helper to send a pendingAction.
 * @param {Function} opts.openSSEStream           - Opens/re-opens the SSE stream.
 * @returns {Function} async (intent: Object) => void
 */
export const createRunPlanAction = ({state, texts, sendPlanningFeedback, openSSEStream}) => {
    /**
     * Send one ActionIntent as pending_action and re-open the planning stream.
     *
     * @param {Object} intent - The pendingAction object to send.
     * @param {Object} [scopeIntent] - The REAL resolved intent driving the left-panel
     *     routing when `intent` is a wrapper (e.g. execute_proposal). Defaults to `intent`.
     * @returns {Promise<void>}
     */
    return async(intent, scopeIntent) => {
        if (!sendPlanningFeedback || !state.sessionid) {
            return;
        }
        // Show the "working" indicator (spinner + message) IMMEDIATELY — before the
        // sendPlanningFeedback round-trip — so the left panel never goes blank while
        // an action (reorder, replan, add, apply proposal…) is being dispatched, and
        // the user gets instant feedback that their request started. handleStatus
        // updates this same entry in place as the server responds.
        showWorkingIndicator(texts);
        // What gets SENT is `intent` (e.g. {action:'execute_proposal', …} when applying
        // a proposal). What decides the LEFT-panel routing is the REAL action, which for
        // a proposal lives in scopeIntent (the proposal's resolved intent). Inline controls
        // pass no scopeIntent, so the sent intent IS the scope source — behaviour unchanged.
        const scope = scopeIntent || intent;
        // Mark a regeneration so the stream handlers route this round's content
        // into a NEW left-panel block (below the user's instruction) instead of
        // rebuilding the top "structure I planned" checklist. ONLY replans set
        // this — reorder/add/delete/accept leave it null, so their flow (already
        // verified) is untouched. Cleared by the lifecycle handlers at stream end.
        const action = scope && scope.action;
        if (action === 'replan_activity' || action === 'replan_section') {
            state.regenScope = {action, targetIds: (scope.target_ids || []).slice()};
        } else {
            state.regenScope = null;
        }
        // Adding an element: snapshot the ids that exist NOW so review_needed can
        // name the one the model just created (the id not in this set). Cleared by
        // the lifecycle handlers, like regenScope.
        if (action === 'add_section') {
            const secs = (state.latestInitialSections || []).filter((s) => s && !s.deleted);
            state.addScope = {action, beforeIds: secs.map((s) => s.id)};
        } else if (action === 'add_activity') {
            const parent = (state.latestInitialSections || []).find((s) => s && s.id === scope.parent_section_id);
            const acts = (parent && parent.activities || []).filter((a) => a && !a.deleted);
            state.addScope = {action, parentSectionId: scope.parent_section_id, beforeIds: acts.map((a) => a.id)};
        } else {
            state.addScope = null;
        }
        await sendPlanningFeedback({recordid: state.sessionid, pendingAction: intent});
        // keepPlan: this resumes an existing plan to apply an action — preserve the
        // rendered preview so the reconciler diffs against it (no teardown/flicker).
        openSSEStream(state.streamingurl, 0, 'planning', true);
    };
};
