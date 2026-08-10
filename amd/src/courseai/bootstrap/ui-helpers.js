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
 * Thin UI helpers shared across the courseai init bootstrap sequence.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-helpers
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createLog} from 'local_coursegen/local/courseai/ui/log';

/**
 * Create the decision-log emitter.
 *
 * Builds a single chronological feed: planning-phase entries render above the
 * section checklist; once the plan has been reviewed at least once, user actions
 * render below it (organic downward chat flow).
 *
 * @param {Object} state - Shared courseai state (read for the settle flag).
 * @returns {{emitLog: Function}}
 */
export const makeEmitLog = (state) => {
    const logContainer = document.getElementById('cgLog');
    const actionContainer = document.getElementById('cgLogAfter');
    const courseaiLog = createLog({
        container: logContainer,
        actionContainer,
        // Either flag means the same thing for the feed: entries belong below the
        // plan card. They are kept separate because planEverReviewed carries other
        // meaning (which checklist streamed sections fill, and how the review
        // milestone is worded) that the reload rebuild must not touch.
        isActionPhase: () => Boolean(state && (state.planEverReviewed || state.threadBelowPlan)),
    });

    /**
     * Emit a turn into the conversation thread.
     *
     * The thread feed (#cgLog) is always visible now — there is no rigid log
     * section to reveal — so this just forwards to the feed renderer.
     *
     * @param {Object} logParams
     * @returns {void}
     */
    const emitLog = (logParams) => {
        courseaiLog.add(logParams);
    };

    /**
     * Wipe the thread feed (both containers). Called when the user leaves a
     * session back to the context form, so the next session starts with a
     * clean transcript instead of appending after the abandoned one.
     *
     * @returns {void}
     */
    const clearLog = () => {
        courseaiLog.clear();
        if (state) {
            // Reset the course-title dedupe so a new session logs its title.
            state.courseTitleLogged = null;
        }
    };

    return {emitLog, clearLog};
};

/**
 * Create the plan-markdown renderer.
 *
 * @param {Object} params
 * @param {Object} params.markedParser
 * @param {Object} params.state
 * @param {Object} params.elements
 * @returns {Function}
 */
export const makeRenderPlanMarkdown = ({markedParser, state, elements}) => {
    /**
     * Re-render the plan markdown buffer into the DOM.
     *
     * @returns {void}
     */
    return () => {
        if (!elements.planMarkdown) {
            return;
        }
        const html = markedParser.parse ? markedParser.parse(state.planBuffer || '') : '';
        elements.planMarkdown.innerHTML = html;
        if (elements.pcDetailsPanel && state.planDetailsOpen) {
            elements.pcDetailsPanel.scrollTop = elements.pcDetailsPanel.scrollHeight;
        }
    };
};
