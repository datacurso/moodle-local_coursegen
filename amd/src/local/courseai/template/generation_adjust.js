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
 * The review of a generated course: the teacher accepts it or asks for changes, and a change request is sent to the run.
 *
 * @module     local_coursegen/local/courseai/template/generation_adjust
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {milestone, turn} from 'local_coursegen/local/courseai/template/thread';
import {resetSeen} from 'local_coursegen/local/courseai/template/generation_events';
import {showGeneratingHeader} from 'local_coursegen/local/courseai/template/generation_header';
import {askForDecision} from 'local_coursegen/local/courseai/template/generation_review';
import {sendTemplateReviewFeedback} from 'local_coursegen/local/courseai/template/repository';
import {adjustable, errorMessage} from 'local_coursegen/local/courseai/template/review_flow';
import {getLabels, paintStage} from 'local_coursegen/local/courseai/template/generation_stage';

/**
 * Send a change request to the run and tell the flow how it went.
 *
 * @param {ReviewFlow} flow
 * @param {{callId: string, instruction: string, aid: string}} sent What the flow validated.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<boolean>} True when the service stored the request.
 */
const sendAdjustment = async(flow, sent, sessionId) => {
    try {
        await sendTemplateReviewFeedback(sessionId, sent.callId, sent.instruction, sent.aid);
    } catch (error) {
        const texts = await getLabels();
        flow.feedbackFailed(error);
        turn('ai', 'danger', errorMessage(error, texts.adjustFailed));
        return false;
    }
    flow.feedbackStored();
    return true;
};

/**
 * The run goes on from its draft after a change request: say so and show it working again.
 *
 * @param {{instruction: string}} sent What was sent.
 * @returns {Promise<void>}
 */
const startAdjustedRound = async(sent) => {
    turn('user', 'user', sent.instruction);
    milestone('courseai_template_log_adjusting');
    resetSeen();
    showGeneratingHeader((await getLabels()).title);
    await paintStage('activities');
};

/**
 * Settle one decision of the teacher.
 *
 * @param {ReviewFlow} flow
 * @param {Object} outcome What the flow answered to the decision.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<string>} 'accepted', 'adjusting' or 'again' when the review stays open.
 */
const settleDecision = async(flow, outcome, sessionId) => {
    if (outcome.screen === 'accepted') {
        return 'accepted';
    }
    if (outcome.error === 'toolong') {
        const texts = await getLabels();
        turn('ai', 'danger', texts.adjustTooLong);
        return 'again';
    }
    if (!outcome.send) {
        return 'again';
    }
    const stored = await sendAdjustment(flow, outcome.send, sessionId);
    if (!stored) {
        return 'again';
    }
    await startAdjustedRound(outcome.send);
    return 'adjusting';
};

/**
 * Keep the review open until the teacher accepts the course or a change request is stored.
 *
 * @param {ReviewFlow} flow
 * @param {{generated: Array<Object>}} shown The review that is on screen.
 * @param {number} sessionId Local session id, for example 139.
 * @returns {Promise<string>} 'accepted' or 'adjusting'.
 */
export const reviewResult = async(flow, shown, sessionId) => {
    const rows = adjustable(shown.generated);
    for (;;) {
        const decision = await askForDecision(rows);
        const outcome = flow.submit(decision);
        const verdict = await settleDecision(flow, outcome, sessionId);
        if (verdict !== 'again') {
            return verdict;
        }
    }
};
