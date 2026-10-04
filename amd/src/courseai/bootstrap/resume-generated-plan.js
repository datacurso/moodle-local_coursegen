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
 * The plan of a generation that has finished, drawn the way the live stream leaves it.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-generated-plan
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createGenerationTracker, markAllTrackerActivitiesDone} from 'local_coursegen/local/courseai/stream/tracker';
import {renderGenerationTracker} from 'local_coursegen/local/courseai/stream/tracker-renderer';

/**
 * Take the header of the plan out of the streaming look it has while the plan is being drawn.
 *
 * @param {Object} root Where the header is looked up by id, normally the document.
 * @returns {void}
 */
const leaveStreamingHeader = (root) => {
    const header = root.getElementById('prvHeader');
    if (header) {
        header.classList.remove('prv-header--stream');
    }
};

/**
 * Show the header of the plan as done: the check instead of the spinner, and the finalizing text.
 *
 * @param {Object} root Where the header elements are looked up by id, normally the document.
 * @param {Object} texts Localized strings.
 * @returns {void}
 */
const showHeaderDone = (root, texts) => {
    const header = root.getElementById('prvHeader');
    const spinner = root.getElementById('prvSpinnerIcon');
    const check = root.getElementById('prvCheckIcon');
    const subtitle = root.getElementById('prvHeaderSub');
    if (header) {
        header.classList.add('prv-header--done');
    }
    if (spinner) {
        spinner.style.display = 'none';
    }
    if (check) {
        check.style.display = '';
    }
    if (subtitle) {
        subtitle.textContent = (texts && texts.courseai_finalizing_course) || 'Finalizing your course…';
    }
};

/**
 * Draw the plan of a finished generation as the live stream leaves it when it completes: every
 * activity checked as generated, the editing controls hidden and the header done.
 *
 * The plan must already be drawn, because the status of every activity is set on its row.
 *
 * @param {Object} params
 * @param {Object} params.state The page state, which keeps the tracker the later steps read.
 * @param {Object} params.texts Localized strings.
 * @param {Object} params.root Where the page elements are looked up, normally the document.
 * @returns {void}
 */
export const showGeneratedPlan = ({state, texts, root}) => {
    leaveStreamingHeader(root);
    root.body.classList.add('cg-generating');
    state.generationTracker = createGenerationTracker(state, texts);
    markAllTrackerActivitiesDone(state, () => renderGenerationTracker(state));
    showHeaderDone(root, texts);
};

/**
 * Draw the approved plan of a generation that is still running, as the live stream has it after the
 * approval: not streaming any more, with its editing controls enabled for the end of the generation.
 *
 * @param {Object} params
 * @param {Object} params.root Where the page elements are looked up, normally the document.
 * @param {Object} params.detailedUi The plan panel.
 * @returns {void}
 */
export const showApprovedPlan = ({root, detailedUi}) => {
    leaveStreamingHeader(root);
    if (typeof detailedUi.enableAllActionControls === 'function') {
        detailedUi.enableAllActionControls();
    }
};
