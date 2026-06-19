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
 * @returns {{emitLog: Function}}
 */
export const makeEmitLog = () => {
    const logContainer = document.getElementById('cgLog');
    const logSection = document.getElementById('cgLogSection');
    const courseaiLog = createLog({container: logContainer});

    /**
     * Show the log section and emit an entry.
     *
     * @param {Object} logParams
     * @returns {void}
     */
    const emitLog = (logParams) => {
        if (logSection && logSection.style.display === 'none') {
            logSection.style.display = '';
        }
        courseaiLog.add(logParams);
    };

    return {emitLog};
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
