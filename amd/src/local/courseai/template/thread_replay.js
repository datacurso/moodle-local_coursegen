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
 * The conversation of a finished generation from a template, written again after a reload.
 *
 * Moodle keeps every step of the run that passed through it: each answer to the review, the building of the course
 * and the activities the AI wrote. They are replayed here with the same turns the live run wrote, below the first
 * message the page already rendered, so a reload shows what the professor saw.
 *
 * @module     local_coursegen/local/courseai/template/thread_replay
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {milestone, turn} from 'local_coursegen/local/courseai/template/thread';
import {showWrittenChecklist} from 'local_coursegen/local/courseai/template/generation_checklist';

/** Body class of a run that is over: its last step is a check. */
const SETTLED_CLASS = 'cg-generation-settled';

/**
 * Seconds since epoch as the ms the thread counts in.
 *
 * @param {number} seconds
 * @returns {number}
 */
const toMs = (seconds) => Number(seconds || 0) * 1000;

/**
 * Write one answer to the review, after the turn that asked for it.
 *
 * @param {Object} review {approved, instruction, time}
 * @returns {Promise<void>}
 */
const replayReview = async(review) => {
    const at = toMs(review.time);
    await milestone('courseai_template_log_review_ready', 'ai', 'ai', at);
    if (review.approved) {
        await milestone('courseai_template_log_approved', 'user', 'success', at);
        return;
    }
    turn('user', 'user', String(review.instruction || '').trim(), false, at);
    await milestone('courseai_template_log_adjusting', 'ai', 'ai', at);
};

/**
 * Write the conversation of a finished run again.
 *
 * @param {Object|null} history {started, reviews, completed, sections}, as the page received it.
 * @returns {Promise<void>}
 */
export const replayRun = async(history) => {
    if (!history || !history.completed) {
        return;
    }
    const started = toMs(history.started);
    await milestone('courseai_template_log_starting', 'ai', 'ai', started);
    await milestone('courseai_template_log_writing', 'ai', 'ai', started);
    for (const review of history.reviews || []) {
        // eslint-disable-next-line no-await-in-loop
        await replayReview(review);
    }
    await milestone('courseai_template_log_completed', 'ai', 'ai', toMs(history.completed));
    document.body.classList.add(SETTLED_CLASS);
    showWrittenChecklist(history.sections || []);
};
