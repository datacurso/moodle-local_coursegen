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

/**
 * Create a runPlanAction function that sends one ActionIntent as pending_action
 * and re-opens the planning SSE stream.
 *
 * @param {Object}   opts
 * @param {Object}   opts.state                  - Shared mutable state (needs sessionid, streamingurl).
 * @param {Function} opts.sendPlanningFeedback    - WS helper to send a pendingAction.
 * @param {Function} opts.openSSEStream           - Opens/re-opens the SSE stream.
 * @returns {Function} async (intent: Object) => void
 */
export const createRunPlanAction = ({state, sendPlanningFeedback, openSSEStream}) => {
    /**
     * Send one ActionIntent as pending_action and re-open the planning stream.
     *
     * @param {Object} intent - The pendingAction object to send.
     * @returns {Promise<void>}
     */
    return async(intent) => {
        if (!sendPlanningFeedback || !state.sessionid) {
            return;
        }
        await sendPlanningFeedback({recordid: state.sessionid, pendingAction: intent});
        // keepPlan: this resumes an existing plan to apply an action — preserve the
        // rendered preview so the reconciler diffs against it (no teardown/flicker).
        openSSEStream(state.streamingurl, 0, 'planning', true);
    };
};
